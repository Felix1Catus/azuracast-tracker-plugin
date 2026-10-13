<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

use App\Container\SettingsAwareTrait;
use Plugin\BranchTracker\Exception\ValidationException;
use RuntimeException;

/**
 * PINs are stored as a keyed HMAC, not password_hash():
 *  - login has no username, so the PIN itself must be the lookup key; an HMAC is deterministic and
 *    indexable, so it allows a fast lookup and a DB-enforced "no two branches share a PIN" rule.
 *  - a 4-digit PIN has only 10,000 values, so a slow hash adds little against offline guessing.
 *    The real protection is the rate limit and lockout on the login endpoint.
 *
 * Key: env BRANCH_TRACKER_PIN_KEY if set, else the install's app_unique_identifier
 * (stable, and carried over by backup/restore, so local copies of prod accept the same PINs).
 * Changing the key invalidates every stored PIN.
 */
final class PinHasher
{
    use SettingsAwareTrait;

    /** Default PIN length. Override per install with env BRANCH_TRACKER_PIN_LENGTH (4-8). */
    public const int DEFAULT_LENGTH = 4;

    /** Pre-existing PINs (imported with --legacy-pins) may be 4-8 digits until rotated. */
    public const int LEGACY_MIN_LENGTH = 4;
    public const int LEGACY_MAX_LENGTH = 8;

    public function hash(int $stationId, string $pin): string
    {
        return hash_hmac('sha256', $stationId . ':' . $pin, $this->key());
    }

    public function assertValid(string $pin, bool $allowLegacy = false): void
    {
        $pattern = $allowLegacy
            ? '/^\d{' . self::LEGACY_MIN_LENGTH . ',' . self::LEGACY_MAX_LENGTH . '}$/'
            : '/^\d{' . self::length() . '}$/';
        if (!preg_match($pattern, $pin)) {
            throw new ValidationException($allowLegacy
                ? sprintf('PIN must be %d to %d digits.', self::LEGACY_MIN_LENGTH, self::LEGACY_MAX_LENGTH)
                : sprintf('PIN must be exactly %d digits.', self::length()));
        }
    }

    /** Length required for new and changed PINs: env BRANCH_TRACKER_PIN_LENGTH (4-8), default 4. */
    public static function length(): int
    {
        $env = getenv('BRANCH_TRACKER_PIN_LENGTH');
        return (false !== $env && ctype_digit($env))
            ? max(self::LEGACY_MIN_LENGTH, min(self::LEGACY_MAX_LENGTH, (int)$env))
            : self::DEFAULT_LENGTH;
    }

    /** A random PIN that avoids the obvious ones (1111, 1234, 9876 ...). Caller checks uniqueness. */
    public function generate(?int $length = null): string
    {
        $length ??= self::length();
        do {
            $pin = str_pad((string)random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
        } while ($this->isWeak($pin));

        return $pin;
    }

    public function isWeak(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }
        $digits = array_map('intval', str_split($pin));
        $steps = array_unique(array_map(
            static fn(int $i) => ($digits[$i + 1] - $digits[$i] + 10) % 10,
            range(0, count($digits) - 2)
        ));
        // Constant step of +1 or -1 (mod 10): 1234, 7890, 4321 ...
        return count($steps) === 1 && in_array(reset($steps), [1, 9], true);
    }

    /** The PIN key, also used (hashed again) by PinVault. */
    public function secret(): string
    {
        return $this->key();
    }

    private function key(): string
    {
        $key = getenv('BRANCH_TRACKER_PIN_KEY') ?: ($this->readSettings()->app_unique_identifier ?? '');
        if ('' === $key) {
            throw new RuntimeException('Branch Tracker: no PIN key (set BRANCH_TRACKER_PIN_KEY).');
        }
        return $key;
    }
}
