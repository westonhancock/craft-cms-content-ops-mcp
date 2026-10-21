<?php

declare(strict_types=1);

namespace westonhancock\editormcp\services;

use InvalidArgumentException;

/**
 * Parses and generates the OAuth key material. Pure PHP (no Craft) so it can
 * be unit tested.
 *
 * Env-supplied private keys may arrive as raw PEM, PEM with literal "\n"
 * escapes (common in host dashboards), or base64-encoded PEM (what
 * `craft editor-mcp/keys/generate` prints).
 */
final class KeyMaterial
{
    public const MIN_ENCRYPTION_KEY_LENGTH = 32;

    /**
     * Returns the key as canonical PEM, or throws if it isn't an unencrypted
     * RSA private key.
     */
    public static function normalizePrivateKey(string $value): string
    {
        $value = trim($value);
        if (str_contains($value, '-----BEGIN')) {
            $pem = str_replace('\n', "\n", $value);
        } else {
            $pem = base64_decode($value, true);
            if ($pem === false || !str_contains($pem, '-----BEGIN')) {
                throw new InvalidArgumentException('expected PEM or base64-encoded PEM');
            }
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new InvalidArgumentException('not a readable private key (passphrase-protected keys are not supported)');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidArgumentException('must be an RSA key');
        }
        if (!openssl_pkey_export($key, $canonical)) {
            throw new InvalidArgumentException('could not export the key');
        }
        return $canonical;
    }

    public static function publicKeyFromPrivate(string $privatePem): string
    {
        $key = openssl_pkey_get_private($privatePem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false) {
            throw new InvalidArgumentException('could not derive the public key');
        }
        return $details['key'];
    }

    public static function normalizeEncryptionKey(string $value): string
    {
        $value = trim($value);
        if (strlen($value) < self::MIN_ENCRYPTION_KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf('must be at least %d characters', self::MIN_ENCRYPTION_KEY_LENGTH));
        }
        return $value;
    }

    public static function generatePrivateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new InvalidArgumentException('failed to generate an RSA key pair');
        }
        return $pem;
    }

    public static function generateEncryptionKey(): string
    {
        return base64_encode(random_bytes(32));
    }
}
