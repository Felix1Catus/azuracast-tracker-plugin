<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use Plugin\BranchTracker\Entity\Branch;
use Plugin\BranchTracker\Entity\BranchSession;
use Plugin\BranchTracker\Entity\Enums\SessionEndReason;
use Plugin\BranchTracker\Exception\ValidationException;

/**
 * All branch rules in one place, shared by the admin API and the CLI importer.
 *
 * NB: AzuraCast forces Doctrine's DEFERRED_EXPLICIT change tracking on every entity, plugins included
 * (App\Doctrine\Event\SetExplicitChangeTracking). A modified entity is only written if it is persist()ed
 * again before flush(); without that, updates are silently dropped.
 */
final class BranchService
{
    use EntityManagerAwareTrait;

    private const int MAX_PIN_GENERATION_ATTEMPTS = 200;

    public function __construct(
        private readonly PinHasher $pinHasher,
    ) {
    }

    /** @return Branch[] */
    public function listForStation(Station $station): array
    {
        return $this->em->createQuery(
            'SELECT b FROM Plugin\BranchTracker\Entity\Branch b WHERE b.station = :station ORDER BY b.code'
        )->setParameter('station', $station)->getResult();
    }

    public function find(Station $station, int $id): ?Branch
    {
        $branch = $this->em->find(Branch::class, $id);
        return ($branch instanceof Branch && $branch->station->id === $station->id) ? $branch : null;
    }

    public function findByCode(Station $station, string $code): ?Branch
    {
        return $this->em->getRepository(Branch::class)->findOneBy(['station' => $station, 'code' => $code]);
    }

    public function findByPin(Station $station, string $pin): ?Branch
    {
        return $this->em->getRepository(Branch::class)->findOneBy([
            'station' => $station,
            'pin_hmac' => $this->pinHasher->hash($station->id, $pin),
        ]);
    }

    /**
     * @return array{0: Branch, 1: string} the branch and its PIN (generated when $pin is null).
     *         The PIN is only ever available here, at creation.
     */
    public function create(Station $station, string $code, string $name, ?string $pin = null, bool $allowLegacyPin = false): array
    {
        $code = $this->cleanCode($code);
        $name = $this->cleanName($name);
        if (null !== $this->findByCode($station, $code)) {
            throw new ValidationException(sprintf('Branch code "%s" already exists.', $code), true);
        }

        $pin = $this->choosePin($station, $pin, null, $allowLegacyPin);
        $branch = new Branch($station, $code, $name, $this->pinHasher->hash($station->id, $pin));
        $this->em->persist($branch);
        $this->em->flush();

        return [$branch, $pin];
    }

    /** Partial update of code / name / is_enabled. Disabling ends the branch's open session. */
    public function update(Branch $branch, array $data): Branch
    {
        if (array_key_exists('code', $data)) {
            $code = $this->cleanCode((string)$data['code']);
            $other = $this->findByCode($branch->station, $code);
            if (null !== $other && $other->id !== $branch->id) {
                throw new ValidationException(sprintf('Branch code "%s" already exists.', $code), true);
            }
            $branch->code = $code;
        }
        if (array_key_exists('name', $data)) {
            $branch->name = $this->cleanName((string)$data['name']);
        }
        if (array_key_exists('is_enabled', $data)) {
            $branch->is_enabled = filter_var($data['is_enabled'], FILTER_VALIDATE_BOOL);
            if (!$branch->is_enabled) {
                $this->endOpenSessions($branch, SessionEndReason::Admin);
            }
        }

        $this->em->persist($branch);
        $this->em->flush();
        return $branch;
    }

    /** Sets (or generates) a new PIN, clears any lockout and ends the open session. Returns the PIN. */
    public function setPin(Branch $branch, ?string $pin = null, bool $allowLegacyPin = false): string
    {
        $pin = $this->choosePin($branch->station, $pin, $branch, $allowLegacyPin);
        $branch->pin_hmac = $this->pinHasher->hash($branch->station->id, $pin);
        $branch->failed_attempts = 0;
        $branch->locked_until = null;
        $this->endOpenSessions($branch, SessionEndReason::Admin);
        $this->em->persist($branch);
        $this->em->flush();

        return $pin;
    }

    public function delete(Branch $branch): void
    {
        $this->em->remove($branch); // sessions and links cascade in the DB
        $this->em->flush();
    }

    public function getOpenSession(Branch $branch): ?BranchSession
    {
        return $this->em->getRepository(BranchSession::class)->findOneBy(['branch' => $branch, 'ended_at' => null]);
    }

    /** Ends every open session of the station (board: "Log out all / Log in again"). Returns how many. */
    public function endAllOpenSessions(Station $station, SessionEndReason $reason): int
    {
        return $this->em->getConnection()->executeStatement(
            'UPDATE branch_session SET ended_at = UTC_TIMESTAMP(6), end_reason = :reason
              WHERE station_id = :station AND ended_at IS NULL',
            ['reason' => $reason->value, 'station' => $station->id]
        );
    }

    public function endOpenSessions(Branch $branch, SessionEndReason $reason): int
    {
        $sessions = $this->em->getRepository(BranchSession::class)->findBy(['branch' => $branch, 'ended_at' => null]);
        foreach ($sessions as $session) {
            $session->end($reason);
            $this->em->persist($session);
        }
        return count($sessions);
    }

    private function choosePin(Station $station, ?string $pin, ?Branch $self, bool $allowLegacy = false): string
    {
        if (null !== $pin && '' !== $pin) {
            $this->pinHasher->assertValid($pin, $allowLegacy);
            if ($this->pinHasher->isWeak($pin)) {
                throw new ValidationException('That PIN is too easy to guess (repeated or sequential digits).');
            }
            if ($this->pinTaken($station, $pin, $self)) {
                throw new ValidationException('That PIN is already used by another branch.', true);
            }
            return $pin;
        }

        for ($i = 0; $i < self::MAX_PIN_GENERATION_ATTEMPTS; $i++) {
            $candidate = $this->pinHasher->generate();
            if (!$this->pinTaken($station, $candidate, $self)) {
                return $candidate;
            }
        }
        throw new ValidationException('Could not generate a free PIN; set one explicitly.', true);
    }

    private function pinTaken(Station $station, string $pin, ?Branch $self): bool
    {
        $owner = $this->findByPin($station, $pin);
        return null !== $owner && $owner->id !== $self?->id;
    }

    private function cleanCode(string $code): string
    {
        $code = trim($code);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/', $code)) {
            throw new ValidationException('Branch code must be 1-50 characters: letters, digits, . _ -');
        }
        return $code;
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ('' === $name || mb_strlen($name) > 100) {
            throw new ValidationException('Branch name must be 1-100 characters.');
        }
        return $name;
    }
}
