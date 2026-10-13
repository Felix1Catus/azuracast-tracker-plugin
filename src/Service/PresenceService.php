<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Plugin\BranchTracker\Entity\Enums\SessionEndReason;

/**
 * Presence and listening time, derived on the server from Icecast connections (see design §3.4, §7).
 *
 * Listening interval for each linked connection:
 *   start = auth_at          (exact connect time from Icecast's listener_add; core's listener.timestamp_start
 *                             lags by one now-playing cycle)
 *   end   = listener.timestamp_end, or now while connected,
 *           capped at min(session end, session.last_seen_at + GRACE), so a stalled now-playing worker or a
 *           dead page can't inflate time (phase 0 saw a 90 s listen recorded as 280 s while the worker was down)
 *
 * All DB times are UTC. Days and the daily reset use the station's timezone.
 */
final class PresenceService
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    /** Heartbeat is every 60 s; allow 2.5 beats before treating the page as gone. */
    public const int GRACE_SECONDS = 150;
    /** No stream and no heartbeat for this long ends the session (timeout). */
    public const int IDLE_TIMEOUT_SECONDS = 300;
    /** Even with a "connected" stream, no heartbeat for this long ends it (copied stream URLs, dead pages). */
    public const int HARD_TIMEOUT_SECONDS = 900;
    /** Window to match an Icecast auth to core's listener row (now-playing runs every ~10-15 s). */
    public const int LINK_WINDOW_SECONDS = 120;
    /** An auth not yet matched to a listener row counts as "connecting" (and accrues time) only this long. */
    public const int PENDING_SECONDS = 45;
    public const string DAILY_RESET_TIME = '00:30';

    // ---------------------------------------------------------------- sync (every minute)

    /** @return array{linked: int, timed_out: int, daily_reset: int} */
    public function sync(Station $station, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        // Daily reset before timeouts: a session spanning 00:30 ends as daily_reset, whatever its activity.
        $linked = $this->linkListeners($station, $now);
        $reset = $this->dailyReset($station, $now);
        return [
            'linked' => $linked,
            'timed_out' => $this->endIdleSessions($station, $now),
            'daily_reset' => $reset,
        ];
    }

    /**
     * Fill branch_session_listener.listener_id from core's listener rows.
     *  1. exact: same Icecast uid and a row that started just after the auth (a fresh connection).
     *     Icecast reuses uids after a restart, hence the time window.
     *  2. fallback: same IP + user agent and a row that was open at auth time. Core keys rows on
     *     md5(ip + agent + mount), so a device that reconnects before core notices the drop continues its
     *     old row under the old uid; the new uid never gets a row of its own.
     */
    public function linkListeners(Station $station, DateTimeImmutable $now): int
    {
        $db = $this->db();
        $pending = $db->fetchAllAssociative(
            'SELECT bsl.id, bsl.listener_uid, bsl.auth_at, bsl.listener_ip, bsl.listener_agent
               FROM branch_session_listener bsl
               JOIN branch_session s ON s.id = bsl.session_id
              WHERE s.station_id = :station AND bsl.listener_id IS NULL AND bsl.auth_at >= :since',
            ['station' => $station->id, 'since' => $this->sql($now->modify('-1 day'))]
        );

        $window = ' INTERVAL ' . self::LINK_WINDOW_SECONDS . ' SECOND';
        $linked = 0;
        foreach ($pending as $row) {
            $params = ['station' => $station->id, 'uid' => $row['listener_uid'], 'auth' => $row['auth_at']];
            $listenerId = $db->fetchOne(
                'SELECT l.id FROM listener l
                  WHERE l.station_id = :station AND l.listener_uid = :uid
                    AND l.timestamp_start BETWEEN DATE_SUB(:auth, INTERVAL 5 SECOND) AND DATE_ADD(:auth,' . $window . ')
                  ORDER BY ABS(TIMESTAMPDIFF(MICROSECOND, :auth, l.timestamp_start))
                  LIMIT 1',
                $params
            );
            if (false === $listenerId && null !== $row['listener_ip'] && null !== $row['listener_agent']) {
                $listenerId = $db->fetchOne(
                    'SELECT l.id FROM listener l
                      WHERE l.station_id = :station AND l.listener_ip = :ip AND l.listener_user_agent = :agent
                        AND l.timestamp_start <= DATE_ADD(:auth,' . $window . ')
                        AND (l.timestamp_end IS NULL OR l.timestamp_end >= DATE_SUB(:auth, INTERVAL 5 SECOND))
                      ORDER BY l.timestamp_start DESC
                      LIMIT 1',
                    $params + ['ip' => $row['listener_ip'], 'agent' => $row['listener_agent']]
                );
            }
            if (false !== $listenerId) {
                $db->update('branch_session_listener', ['listener_id' => $listenerId], ['id' => $row['id']]);
                $linked++;
            }
        }
        return $linked;
    }

    public function endIdleSessions(Station $station, DateTimeImmutable $now): int
    {
        $idleBefore = $this->sql($now->modify('-' . self::IDLE_TIMEOUT_SECONDS . ' seconds'));
        $hardBefore = $this->sql($now->modify('-' . self::HARD_TIMEOUT_SECONDS . ' seconds'));

        // Idle: no heartbeat recently AND no open stream. Hard: no heartbeat for a long time, stream or not.
        // End time = last sign of life: the later of the last heartbeat and the end of its last stream
        // (a page that closed mid-stream stops heartbeating, but its audio ran until the stream closed).
        return $this->db()->executeStatement(
            'UPDATE branch_session s
                SET s.ended_at = GREATEST(s.last_seen_at, COALESCE((
                        SELECT MAX(l.timestamp_end) FROM branch_session_listener bsl
                          JOIN listener l ON l.id = bsl.listener_id
                         WHERE bsl.session_id = s.id AND l.timestamp_end <= :now), s.last_seen_at)),
                    s.end_reason = :reason
              WHERE s.station_id = :station AND s.ended_at IS NULL
                AND (s.last_seen_at < :hard
                     OR (s.last_seen_at < :idle AND NOT EXISTS (
                           SELECT 1 FROM branch_session_listener bsl
                             JOIN listener l ON l.id = bsl.listener_id
                            WHERE bsl.session_id = s.id AND l.timestamp_end IS NULL)))',
            [
                'reason' => SessionEndReason::Timeout->value,
                'station' => $station->id,
                'idle' => $idleBefore,
                'hard' => $hardBefore,
                'now' => $this->sql($now),
            ]
        );
    }

    /** Ends every session that started before the most recent 00:30 (station time). Idempotent. */
    public function dailyReset(Station $station, DateTimeImmutable $now): int
    {
        $boundary = $this->lastDailyReset($station, $now);
        return $this->db()->executeStatement(
            'UPDATE branch_session SET ended_at = :boundary, end_reason = :reason
              WHERE station_id = :station AND ended_at IS NULL AND started_at < :boundary',
            [
                'boundary' => $this->sql($boundary),
                'reason' => SessionEndReason::DailyReset->value,
                'station' => $station->id,
            ]
        );
    }

    public function lastDailyReset(Station $station, DateTimeImmutable $now): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', self::DAILY_RESET_TIME));
        $local = $now->setTimezone($this->tz($station));
        $boundary = $local->setTime($h, $m);
        if ($local < $boundary) {
            $boundary = $boundary->modify('-1 day');
        }
        return $boundary->setTimezone(new DateTimeZone('UTC'));
    }

    // ---------------------------------------------------------------- read models

    /** Live status for every branch of the station. */
    public function live(Station $station, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tz = $this->tz($station);
        $dayStart = $now->setTimezone($tz)->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));

        $branches = $this->db()->fetchAllAssociative(
            'SELECT b.id, b.code, b.name, b.is_enabled, b.company,
                    s.id AS session_id, s.started_at, s.last_seen_at, s.volume, s.client_state, s.ip, s.user_agent
               FROM branch_branch b
               LEFT JOIN branch_session s ON s.branch_id = b.id AND s.ended_at IS NULL
              WHERE b.station_id = :station
              ORDER BY b.code',
            ['station' => $station->id]
        );

        // Listening today, per branch, from all of today's sessions.
        $todaySessions = $this->sessionsOverlapping($station, $dayStart, $now);
        $listenedToday = [];
        foreach ($this->intervalsBySession($todaySessions, $now) as $sid => $intervals) {
            $bid = $todaySessions[$sid]['branch_id'];
            $listenedToday[$bid] = ($listenedToday[$bid] ?? 0) + $this->clippedSeconds($intervals, $dayStart, $now);
        }

        $openSessions = array_filter(array_column($branches, 'session_id'));
        $streaming = $this->openStreams($openSessions, $now);
        $hasLinks = $this->sessionsWithLinks($openSessions);

        $out = [];
        foreach ($branches as $b) {
            $status = 'offline';
            $source = null;
            if (null !== $b['session_id']) {
                $fresh = $this->ts($b['last_seen_at']) >= $now->getTimestamp() - self::GRACE_SECONDS;
                if (isset($streaming[$b['session_id']]) && $fresh) {
                    [$status, $source] = ['playing', 'stream'];
                } elseif (!isset($hasLinks[$b['session_id']]) && $fresh && 'playing' === $b['client_state']) {
                    // Stream auth off: no server-side connection data, fall back to what the player reports.
                    [$status, $source] = ['playing', 'player'];
                } else {
                    $status = 'online';
                }
            }

            $out[] = [
                'id' => (int)$b['id'],
                'code' => $b['code'],
                'name' => $b['name'],
                'is_enabled' => (bool)$b['is_enabled'],
                'company' => $b['company'],
                'status' => $status,          // playing | online (signed in, not playing) | offline
                'status_source' => $source,   // stream (server-observed) | player (self-reported) | null
                'session' => null === $b['session_id'] ? null : [
                    'id' => (int)$b['session_id'],
                    'started_at' => $this->iso($b['started_at'], $tz),
                    'last_seen_at' => $this->iso($b['last_seen_at'], $tz),
                    'streaming_since' => isset($streaming[$b['session_id']])
                        ? $this->iso($streaming[$b['session_id']], $tz) : null,
                    'volume' => null === $b['volume'] ? null : (int)$b['volume'],
                    'client_state' => $b['client_state'],
                    'ip' => $b['ip'],
                    'user_agent' => $b['user_agent'],
                ],
                'listened_today_seconds' => (int)round($listenedToday[$b['id']] ?? 0),
            ];
        }
        return $out;
    }

    /** Sessions overlapping [from, to), newest first, with listened seconds inside the range. */
    public function sessions(Station $station, DateTimeImmutable $from, DateTimeImmutable $to, ?int $branchId = null, int $limit = 1000): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tz = $this->tz($station);
        $rows = $this->sessionsOverlapping($station, $from, $to, $branchId, $limit);
        $intervals = $this->intervalsBySession($rows, $now);
        $rangeEnd = $to < $now ? $to : $now;

        $out = [];
        foreach ($rows as $sid => $r) {
            $end = null === $r['ended_at'] ? $now->getTimestamp() : $this->ts($r['ended_at']);
            $out[] = [
                'id' => $sid,
                'branch' => ['id' => (int)$r['branch_id'], 'code' => $r['code'], 'name' => $r['name']],
                'started_at' => $this->iso($r['started_at'], $tz),
                'ended_at' => null === $r['ended_at'] ? null : $this->iso($r['ended_at'], $tz),
                'end_reason' => $r['end_reason'],
                'session_seconds' => max(0, $end - $this->ts($r['started_at'])),
                'listened_seconds' => (int)round($this->clippedSeconds($intervals[$sid] ?? [], $from, $rangeEnd)),
                'connections' => count($intervals[$sid] ?? []),
                'ip' => $r['ip'],
                'user_agent' => $r['user_agent'],
            ];
        }
        return $out;
    }

    /**
     * Per branch per calendar day (station time) between two local dates inclusive.
     * @return array{days: string[], rows: array<int, array>}
     */
    public function report(Station $station, string $fromDate, string $toDate): array
    {
        $tz = $this->tz($station);
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);
        $from = new DateTimeImmutable($fromDate . ' 00:00:00', $tz);
        $toExclusive = (new DateTimeImmutable($toDate . ' 00:00:00', $tz))->modify('+1 day');

        $days = [];
        for ($d = $from; $d < $toExclusive; $d = $d->modify('+1 day')) {
            $days[] = $d->format('Y-m-d');
        }

        $sessions = $this->sessionsOverlapping($station, $from->setTimezone($utc), $toExclusive->setTimezone($utc), null, 100000);
        $intervals = $this->intervalsBySession($sessions, $now);

        $branches = $this->db()->fetchAllAssociative(
            'SELECT id, code, name FROM branch_branch WHERE station_id = :s ORDER BY code',
            ['s' => $station->id]
        );
        $grid = [];
        foreach ($branches as $b) {
            $grid[(int)$b['id']] = [
                'branch' => ['id' => (int)$b['id'], 'code' => $b['code'], 'name' => $b['name']],
                'days' => array_fill_keys($days, ['listened_seconds' => 0, 'sessions' => 0, 'first_login' => null]),
                'total_listened_seconds' => 0,
                'total_sessions' => 0,
            ];
        }

        foreach ($sessions as $sid => $s) {
            $bid = (int)$s['branch_id'];
            if (!isset($grid[$bid])) {
                continue;
            }
            $loginLocal = (new DateTimeImmutable($s['started_at'], $utc))->setTimezone($tz);
            $loginDay = $loginLocal->format('Y-m-d');
            if (isset($grid[$bid]['days'][$loginDay])) {
                $cell = &$grid[$bid]['days'][$loginDay];
                $cell['sessions']++;
                $time = $loginLocal->format('H:i');
                if (null === $cell['first_login'] || $time < $cell['first_login']) {
                    $cell['first_login'] = $time;
                }
                unset($cell);
                $grid[$bid]['total_sessions']++;
            }
            foreach ($days as $day) {
                $ds = new DateTimeImmutable($day . ' 00:00:00', $tz);
                $sec = $this->clippedSeconds($intervals[$sid] ?? [], $ds, $ds->modify('+1 day'));
                $grid[$bid]['days'][$day]['listened_seconds'] += (int)round($sec);
                $grid[$bid]['total_listened_seconds'] += (int)round($sec);
            }
        }

        return ['days' => $days, 'rows' => array_values($grid)];
    }

    // ---------------------------------------------------------------- internals

    /** @return array<int, array> keyed by session id */
    private function sessionsOverlapping(Station $station, DateTimeImmutable $from, DateTimeImmutable $to, ?int $branchId = null, int $limit = 100000): array
    {
        $sql = 'SELECT s.id, s.branch_id, b.code, b.name, s.started_at, s.ended_at, s.end_reason, s.last_seen_at, s.ip, s.user_agent
                  FROM branch_session s JOIN branch_branch b ON b.id = s.branch_id
                 WHERE s.station_id = :station AND s.started_at < :to AND (s.ended_at IS NULL OR s.ended_at >= :from)'
            . (null !== $branchId ? ' AND s.branch_id = :branch' : '')
            . ' ORDER BY s.started_at DESC LIMIT ' . max(1, $limit);
        $params = ['station' => $station->id, 'from' => $this->sql($from), 'to' => $this->sql($to)];
        if (null !== $branchId) {
            $params['branch'] = $branchId;
        }
        $rows = [];
        foreach ($this->db()->fetchAllAssociative($sql, $params) as $r) {
            $rows[(int)$r['id']] = $r;
        }
        return $rows;
    }

    /**
     * @param array<int, array> $sessions keyed by id (needs started_at, ended_at, last_seen_at)
     * @return array<int, array<int, array{start: float, end: float}>>
     */
    private function intervalsBySession(array $sessions, DateTimeImmutable $now): array
    {
        if ([] === $sessions) {
            return [];
        }
        $rows = $this->db()->fetchAllAssociative(
            'SELECT bsl.session_id, bsl.auth_at, bsl.listener_id, l.timestamp_end
               FROM branch_session_listener bsl LEFT JOIN listener l ON l.id = bsl.listener_id
              WHERE bsl.session_id IN (:ids)',
            ['ids' => array_keys($sessions)],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $nowTs = $now->getTimestamp();
        $out = [];
        foreach ($rows as $r) {
            $s = $sessions[(int)$r['session_id']];
            $start = $this->ts($r['auth_at']);
            if (null === $r['listener_id']) {
                // Not matched (yet): count only very recent auths as "connecting", else it never really played.
                if ($start < $nowTs - self::PENDING_SECONDS || null !== $s['ended_at']) {
                    continue;
                }
                $end = $nowTs;
            } else {
                $end = null === $r['timestamp_end'] ? $nowTs : $this->ts($r['timestamp_end']);
            }
            $cap = min(
                null === $s['ended_at'] ? $nowTs : $this->ts($s['ended_at']),
                $this->ts($s['last_seen_at']) + self::GRACE_SECONDS
            );
            $end = min($end, $cap, $nowTs);
            if ($end > $start) {
                $out[(int)$r['session_id']][] = ['start' => $start, 'end' => $end];
            }
        }
        return $out;
    }

    /** Merged (overlap-free) seconds of $intervals inside [from, to). */
    private function clippedSeconds(array $intervals, DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        $a = $from->getTimestamp();
        $b = $to->getTimestamp();
        $clipped = [];
        foreach ($intervals as $i) {
            $s = max($i['start'], $a);
            $e = min($i['end'], $b);
            if ($e > $s) {
                $clipped[] = ['start' => $s, 'end' => $e];
            }
        }
        usort($clipped, static fn($x, $y) => $x['start'] <=> $y['start']);
        $total = 0.0;
        $curS = $curE = null;
        foreach ($clipped as $i) {
            if (null === $curE || $i['start'] > $curE) {
                if (null !== $curE) {
                    $total += $curE - $curS;
                }
                [$curS, $curE] = [$i['start'], $i['end']];
            } else {
                $curE = max($curE, $i['end']);
            }
        }
        if (null !== $curE) {
            $total += $curE - $curS;
        }
        return $total;
    }

    /** @return array<int, string> session id => connect time (UTC) of its currently-open stream */
    private function openStreams(array $sessionIds, DateTimeImmutable $now): array
    {
        if ([] === $sessionIds) {
            return [];
        }
        $rows = $this->db()->fetchAllAssociative(
            'SELECT bsl.session_id, MIN(bsl.auth_at) AS since
               FROM branch_session_listener bsl LEFT JOIN listener l ON l.id = bsl.listener_id
              WHERE bsl.session_id IN (:ids)
                AND ((bsl.listener_id IS NOT NULL AND l.timestamp_end IS NULL)
                     OR (bsl.listener_id IS NULL AND bsl.auth_at >= :recent))
              GROUP BY bsl.session_id',
            ['ids' => array_values($sessionIds), 'recent' => $this->sql($now->modify('-' . self::PENDING_SECONDS . ' seconds'))],
            ['ids' => ArrayParameterType::INTEGER]
        );
        return array_column($rows, 'since', 'session_id');
    }

    private function sessionsWithLinks(array $sessionIds): array
    {
        if ([] === $sessionIds) {
            return [];
        }
        $ids = $this->db()->fetchFirstColumn(
            'SELECT DISTINCT session_id FROM branch_session_listener WHERE session_id IN (:ids)',
            ['ids' => array_values($sessionIds)],
            ['ids' => ArrayParameterType::INTEGER]
        );
        return array_fill_keys(array_map('intval', $ids), true);
    }

    private function db(): Connection
    {
        return $this->em->getConnection();
    }

    private function tz(Station $station): DateTimeZone
    {
        try {
            return new DateTimeZone($station->timezone ?: 'UTC');
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    private function sql(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function ts(string $dbUtc): int
    {
        return (new DateTimeImmutable($dbUtc, new DateTimeZone('UTC')))->getTimestamp();
    }

    private function iso(string $dbUtc, DateTimeZone $tz): string
    {
        return (new DateTimeImmutable($dbUtc, new DateTimeZone('UTC')))->setTimezone($tz)->format(DATE_ATOM);
    }
}
