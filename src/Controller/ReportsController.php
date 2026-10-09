<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Controller;

use App\Http\Response;
use App\Http\ServerRequest;
use DateTimeImmutable;
use DateTimeZone;
use Plugin\BranchTracker\Service\PresenceService;
use Psr\Http\Message\ResponseInterface;

/**
 * Dashboard data (permission "view branches"):
 *   GET /api/station/{station}/branches/live
 *   GET /api/station/{station}/branches/sessions?from=YYYY-MM-DD&to=YYYY-MM-DD[&branch_id=][&format=csv]
 *   GET /api/station/{station}/branches/report?from=YYYY-MM-DD&to=YYYY-MM-DD[&format=csv]
 * Dates are station-local calendar days, inclusive. Default: the last 7 days.
 */
final class ReportsController
{
    public const int MAX_DAYS = 92;

    public function __construct(
        private readonly PresenceService $presence,
    ) {
    }

    public function liveAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        return $response->withJson([
            'station' => ['short_name' => $station->short_name, 'name' => $station->name, 'timezone' => $station->timezone],
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone($station->timezone ?: 'UTC')))->format(DATE_ATOM),
            'branches' => $this->publicSafe($request, $this->presence->live($station), 'session'),
        ]);
    }

    public function sessionsAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        [$from, $to, $error] = $this->range($request, $station->timezone);
        if (null !== $error) {
            return $this->error($response, $error);
        }
        $branchId = $request->getQueryParam('branch_id');
        $tz = new DateTimeZone($station->timezone ?: 'UTC');
        $utc = new DateTimeZone('UTC');

        $rows = $this->presence->sessions(
            $station,
            (new DateTimeImmutable($from, $tz))->setTimezone($utc),
            (new DateTimeImmutable($to, $tz))->modify('+1 day')->setTimezone($utc),
            is_numeric($branchId) ? (int)$branchId : null
        );

        if ('csv' === $request->getQueryParam('format') && !$this->isPublic($request)) {
            return $this->csv($response, "branch-sessions_{$station->short_name}_{$from}_{$to}.csv", [
                ['Branch ID', 'Branch', 'Login', 'Logout', 'End reason', 'Session (h:mm:ss)', 'Listened (h:mm:ss)', 'Connections', 'IP', 'Device'],
                ...array_map(static fn(array $r) => [
                    $r['branch']['code'], $r['branch']['name'], $r['started_at'], $r['ended_at'] ?? '', $r['end_reason'] ?? 'open',
                    self::hms($r['session_seconds']), self::hms($r['listened_seconds']), $r['connections'], $r['ip'], $r['user_agent'],
                ], $rows),
            ]);
        }
        return $response->withJson(['from' => $from, 'to' => $to, 'sessions' => $this->publicSafe($request, $rows, null)]);
    }

    public function reportAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        [$from, $to, $error] = $this->range($request, $station->timezone);
        if (null !== $error) {
            return $this->error($response, $error);
        }
        $report = $this->presence->report($station, $from, $to);

        if ('csv' === $request->getQueryParam('format') && !$this->isPublic($request)) {
            $header = ['Branch ID', 'Branch'];
            foreach ($report['days'] as $d) {
                $header[] = $d;
            }
            $header[] = 'Total listened';
            $header[] = 'Sessions';
            $lines = [$header];
            foreach ($report['rows'] as $row) {
                $line = [$row['branch']['code'], $row['branch']['name']];
                foreach ($report['days'] as $d) {
                    $line[] = self::hms($row['days'][$d]['listened_seconds']);
                }
                $line[] = self::hms($row['total_listened_seconds']);
                $line[] = $row['total_sessions'];
                $lines[] = $line;
            }
            return $this->csv($response, "branch-report_{$station->short_name}_{$from}_{$to}.csv", $lines);
        }
        return $response->withJson(['from' => $from, 'to' => $to] + $report);
    }

    /** @return array{0: string, 1: string, 2: ?string} from, to (Y-m-d, station-local, inclusive), error */
    private function range(ServerRequest $request, ?string $timezone): array
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone($timezone ?: 'UTC')))->format('Y-m-d');
        $to = (string)($request->getQueryParam('to') ?? $today);
        $from = (string)($request->getQueryParam('from') ?? (new DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d'));

        foreach ([$from, $to] as $d) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
            if (false === $parsed || $parsed->format('Y-m-d') !== $d) {
                return [$from, $to, 'Dates must be YYYY-MM-DD.'];
            }
        }
        if ($from > $to) {
            return [$from, $to, '"from" must not be after "to".'];
        }
        $days = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
        if ($days > self::MAX_DAYS) {
            return [$from, $to, sprintf('At most %d days per request.', self::MAX_DAYS)];
        }
        return [$from, $to, null];
    }

    private function isPublic(ServerRequest $request): bool
    {
        return true === $request->getAttribute(PublicBoardController::ATTR);
    }

    /** On the public board: drop IP addresses and devices (from $rows[i] or $rows[i][$nested]). */
    private function publicSafe(ServerRequest $request, array $rows, ?string $nested): array
    {
        if (!$this->isPublic($request)) {
            return $rows;
        }
        foreach ($rows as &$r) {
            if (null === $nested) {
                unset($r['ip'], $r['user_agent']);
            } elseif (is_array($r[$nested] ?? null)) {
                unset($r[$nested]['ip'], $r[$nested]['user_agent']);
            }
        }
        unset($r);
        return $rows;
    }

    private function csv(Response $response, string $filename, array $lines): ResponseInterface
    {
        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 (Arabic branch names) correctly
        foreach ($lines as $line) {
            fputcsv($fh, $line, escape: '');
        }
        rewind($fh);
        $response->getBody()->write((string)stream_get_contents($fh));
        fclose($fh);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    private function error(Response $response, string $message): ResponseInterface
    {
        return $response->withStatus(400)->withJson(['success' => false, 'message' => $message, 'formatted_message' => $message]);
    }

    public static function hms(int $seconds): string
    {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
