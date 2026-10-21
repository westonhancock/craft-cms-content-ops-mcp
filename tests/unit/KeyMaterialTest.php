<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tests\unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use westonhancock\editormcp\services\KeyMaterial;

/**
 * Env-supplied keys arrive in several encodings. These tests pin which ones
 * parse to the same key and which ones fail loudly (no Craft needed).
 */
final class KeyMaterialTest extends TestCase
{
    private static string $pem;

    public static function setUpBeforeClass(): void
    {
        // 2048 bits keeps the suite fast; production keys are 4096.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        self::$pem = $pem;
    }

    /**
     * @return array<string, array{callable(string): string}>
     */
    public static function encodingProvider(): array
    {
        return [
            'raw PEM' => [fn(string $pem) => $pem],
            'base64 PEM' => [fn(string $pem) => base64_encode($pem)],
            'escaped newlines' => [fn(string $pem) => str_replace("\n", '\n', trim($pem))],
            'surrounding whitespace' => [fn(string $pem) => "  \n" . base64_encode($pem) . "\n"],
        ];
    }

    /**
     * @dataProvider encodingProvider
     * @param callable(string): string $encode
     */
    public function testEncodingsNormalizeToTheSameKey(callable $encode): void
    {
        $normalized = KeyMaterial::normalizePrivateKey($encode(self::$pem));
        $this->assertSame(
            KeyMaterial::publicKeyFromPrivate(self::$pem),
            KeyMaterial::publicKeyFromPrivate($normalized),
        );
    }

    public function testNormalizedKeySignsAndVerifies(): void
    {
        $private = KeyMaterial::normalizePrivateKey(base64_encode(self::$pem));
        $public = KeyMaterial::publicKeyFromPrivate($private);
        $this->assertTrue(openssl_sign('payload', $sig, $private, OPENSSL_ALGO_SHA256));
        $this->assertSame(1, openssl_verify('payload', $sig, $public, OPENSSL_ALGO_SHA256));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'not base64' => ['not a key!'],
            'base64 of junk' => [base64_encode('hello')],
            'truncated PEM' => ["-----BEGIN PRIVATE KEY-----\nMIIabc\n-----END PRIVATE KEY-----"],
        ];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testInvalidKeysThrow(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyMaterial::normalizePrivateKey($value);
    }

    public function testEcKeyIsRejected(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RSA');
        KeyMaterial::normalizePrivateKey($pem);
    }

    public function testShortEncryptionKeyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyMaterial::normalizeEncryptionKey('too-short');
    }

    public function testGeneratedEncryptionKeyIsAccepted(): void
    {
        $key = KeyMaterial::generateEncryptionKey();
        $this->assertSame($key, KeyMaterial::normalizeEncryptionKey(" $key\n"));
    }
}
