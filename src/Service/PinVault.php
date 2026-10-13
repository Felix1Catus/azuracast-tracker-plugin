<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

/**
 * Keeps each branch's PIN readable for managers (the board's PIN column), next to the HMAC used for login.
 * Encrypted with libsodium's secretbox; the key is derived from the PIN key (PinHasher), so it's never stored
 * in the database. If the PIN key changes, old values can't be decrypted and simply show as unknown.
 */
final class PinVault
{
    public function __construct(
        private readonly PinHasher $hasher,
    ) {
    }

    public function encrypt(string $pin): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($pin, $nonce, $this->key()));
    }

    public function decrypt(?string $stored): ?string
    {
        if (null === $stored || '' === $stored) {
            return null;
        }
        $raw = base64_decode($stored, true);
        if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $pin = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key()
        );
        return false === $pin ? null : $pin;
    }

    private function key(): string
    {
        return sodium_crypto_generichash('branch-tracker:pin-vault:' . $this->hasher->secret(), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
