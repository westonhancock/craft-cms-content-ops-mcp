<?php

declare(strict_types=1);

namespace westonhancock\editormcp\services;

use Craft;
use craft\helpers\App;
use DateInterval;
use DateTimeImmutable;
use Defuse\Crypto\Crypto;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser as JwtParser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;
use westonhancock\editormcp\oauth\Repositories\AccessTokenRepository;
use westonhancock\editormcp\oauth\Repositories\AuthCodeRepository;
use westonhancock\editormcp\oauth\Repositories\ClientRepository;
use westonhancock\editormcp\oauth\Repositories\RefreshTokenRepository;
use westonhancock\editormcp\oauth\Repositories\ScopeRepository;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\records\AccessTokenRecord;
use westonhancock\editormcp\records\RefreshTokenRecord;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Owns the league/oauth2-server instance and key material.
 *
 * Two secrets: the RSA private key (signs access-token JWTs; the public key is
 * derived from it) and the encryption key (encrypts refresh tokens). Each
 * comes from its plugin setting (`privateKey` / `encryptionKey`, usually an
 * `$ENV_VAR` reference) when set, otherwise from storage/editor-mcp/keys,
 * where it is generated on first use. Hosts that wipe storage on deploy
 * should use the settings, or every connected client is signed out.
 */
class TokenService extends Component
{
    public const ACCESS_TTL_MAX = 3600;

    private ?AuthorizationServer $authServer = null;
    private ?ResourceServer $resourceServer = null;
    private ?string $privateKeyPem = null;
    private ?string $encryptionKeyValue = null;

    public function getAuthorizationServer(): AuthorizationServer
    {
        if ($this->authServer !== null) {
            return $this->authServer;
        }

        $settings = Plugin::$plugin->getSettings();
        $accessTtl = min($settings->accessTokenTtl, self::ACCESS_TTL_MAX);

        $server = new AuthorizationServer(
            new ClientRepository(),
            new AccessTokenRepository(),
            new ScopeRepository(),
            $this->privateKey(),
            $this->encryptionKey(),
        );

        // Authorization Code grant + PKCE (S256 only — plaintext rejected via custom request validation)
        $authCodeGrant = new AuthCodeGrant(
            new AuthCodeRepository(),
            new RefreshTokenRepository(),
            new DateInterval('PT' . $settings->authCodeTtl . 'S'),
        );
        $authCodeGrant->setRefreshTokenTTL(new DateInterval('PT' . $settings->refreshTokenTtl . 'S'));
        $server->enableGrantType($authCodeGrant, new DateInterval('PT' . $accessTtl . 'S'));

        // Refresh token grant with rotation (rotation is enforced inside RefreshTokenRepository on persist)
        $refreshGrant = new RefreshTokenGrant(new RefreshTokenRepository());
        $refreshGrant->setRefreshTokenTTL(new DateInterval('PT' . $settings->refreshTokenTtl . 'S'));
        $server->enableGrantType($refreshGrant, new DateInterval('PT' . $accessTtl . 'S'));

        return $this->authServer = $server;
    }

    public function getResourceServer(): ResourceServer
    {
        return $this->resourceServer ??= new ResourceServer(
            new AccessTokenRepository(),
            $this->publicKey(),
        );
    }

    /**
     * Validates a bearer token from a PSR-7 request and returns the parsed claims:
     *   [ 'userId' => int, 'clientId' => string, 'scopes' => string[], 'tokenId' => string ]
     *
     * Throws on missing or invalid token.
     */
    public function authenticateRequest(ServerRequestInterface $request): array
    {
        $authed = $this->getResourceServer()->validateAuthenticatedRequest($request);
        $tokenId = (string) $authed->getAttribute('oauth_access_token_id');
        $userId = (int) $authed->getAttribute('oauth_user_id');
        $clientId = (string) $authed->getAttribute('oauth_client_id');
        $scopes = (array) $authed->getAttribute('oauth_scopes', []);

        // Update last_used_at for token activity column
        AccessTokenRecord::updateAll(
            ['lastUsedAt' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')],
            ['tokenId' => $tokenId],
        );

        return compact('userId', 'clientId', 'scopes', 'tokenId');
    }

    /**
     * Revokes a token presented in its *raw* over-the-wire form, per RFC 7009.
     *
     * Access tokens are RS256 JWTs — we verify the signature against our own
     * public key, then revoke by the `jti` claim. Refresh tokens are
     * Defuse-encrypted JSON payloads (league/oauth2-server's CryptTrait) —
     * we decrypt with the server encryption key and revoke by
     * `refresh_token_id`, which also kills the paired access token.
     *
     * Returns true if a known token was revoked. Unknown/garbage tokens
     * return false — RFC 7009 §2.2 requires the endpoint to respond 200
     * regardless, so callers should not turn false into an error.
     */
    public function revokeRawToken(string $token): bool
    {
        // Access token path: parse as JWT, verify it's ours, revoke by jti.
        try {
            $parsed = (new JwtParser(new JoseEncoder()))->parse($token);
            $signedByUs = (new Validator())->validate(
                $parsed,
                new SignedWith(new Sha256(), InMemory::plainText(KeyMaterial::publicKeyFromPrivate($this->privateKeyPem()))),
            );
            if ($signedByUs && $parsed instanceof UnencryptedToken) {
                $jti = $parsed->claims()->get('jti');
                if (is_string($jti) && $jti !== '') {
                    (new AccessTokenRepository())->revokeAccessToken($jti);
                    return true;
                }
            }
        } catch (\Throwable) {
            // Not a JWT — fall through to the refresh-token path.
        }

        // Refresh token path: decrypt the payload for its refresh_token_id.
        try {
            $json = Crypto::decryptWithPassword($token, $this->encryptionKey());
            $data = json_decode($json, true);
            $id = is_array($data) ? ($data['refresh_token_id'] ?? null) : null;
            if (is_string($id) && $id !== '') {
                (new RefreshTokenRepository())->forceRevoke($id);
                return true;
            }
        } catch (\Throwable) {
            // Unknown token. RFC 7009 says the endpoint still responds 200.
        }
        return false;
    }

    public function revokeAllForUser(int $userId): void
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        AccessTokenRecord::updateAll(['revokedAt' => $now],
            ['and', ['userId' => $userId], ['revokedAt' => null]]);
        RefreshTokenRecord::updateAll(['revokedAt' => $now],
            ['and', ['userId' => $userId], ['revokedAt' => null]]);
    }

    public function revokeAllForClient(int $clientId): void
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        AccessTokenRecord::updateAll(['revokedAt' => $now],
            ['and', ['clientId' => $clientId], ['revokedAt' => null]]);
        RefreshTokenRecord::updateAll(['revokedAt' => $now],
            ['and', ['clientId' => $clientId], ['revokedAt' => null]]);
    }

    public function revokeAll(): void
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        AccessTokenRecord::updateAll(['revokedAt' => $now], ['revokedAt' => null]);
        RefreshTokenRecord::updateAll(['revokedAt' => $now], ['revokedAt' => null]);
    }

    /**
     * Where each secret comes from, for the settings page.
     *
     * @return array{privateKey: 'environment'|'storage', encryptionKey: 'environment'|'storage'}
     */
    public function keySources(): array
    {
        return [
            'privateKey' => $this->configuredValue('privateKey') !== null ? 'environment' : 'storage',
            'encryptionKey' => $this->configuredValue('encryptionKey') !== null ? 'environment' : 'storage',
        ];
    }

    /**
     * Reads the existing key files without generating anything, so they can be
     * moved into env vars without signing anyone out.
     *
     * @return array{privateKey: string, encryptionKey: string}
     */
    public function readStorageKeys(): array
    {
        $out = [];
        foreach (['privateKey' => 'private.key', 'encryptionKey' => 'encryption.key'] as $k => $file) {
            $path = $this->keyDir() . '/' . $file;
            if (!is_file($path)) {
                throw new InvalidConfigException("$path does not exist");
            }
            $out[$k] = trim((string) file_get_contents($path));
        }
        return $out;
    }

    private function privateKey(): CryptKey
    {
        return new CryptKey($this->privateKeyPem(), null, false);
    }

    private function publicKey(): CryptKey
    {
        return new CryptKey(KeyMaterial::publicKeyFromPrivate($this->privateKeyPem()), null, false);
    }

    private function privateKeyPem(): string
    {
        if ($this->privateKeyPem !== null) {
            return $this->privateKeyPem;
        }
        $configured = $this->configuredValue('privateKey');
        if ($configured !== null) {
            try {
                return $this->privateKeyPem = KeyMaterial::normalizePrivateKey($configured);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidConfigException('Editor MCP: the privateKey setting is invalid: ' . $e->getMessage());
            }
        }

        $path = $this->keyDir() . '/private.key';
        if (!is_file($path)) {
            $this->writeKeyFile('private.key', KeyMaterial::generatePrivateKey());
        }
        return $this->privateKeyPem = (string) file_get_contents($path);
    }

    private function encryptionKey(): string
    {
        if ($this->encryptionKeyValue !== null) {
            return $this->encryptionKeyValue;
        }
        $configured = $this->configuredValue('encryptionKey');
        if ($configured !== null) {
            try {
                return $this->encryptionKeyValue = KeyMaterial::normalizeEncryptionKey($configured);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidConfigException('Editor MCP: the encryptionKey setting is invalid: ' . $e->getMessage());
            }
        }

        $path = $this->keyDir() . '/encryption.key';
        if (!is_file($path)) {
            $this->writeKeyFile('encryption.key', KeyMaterial::generateEncryptionKey());
        }
        return $this->encryptionKeyValue = trim((string) file_get_contents($path));
    }

    /**
     * The setting's resolved value, or null to fall back to storage. A setting
     * that points at a missing env var falls back too, with a warning, so a
     * dev machine without the var still works.
     */
    private function configuredValue(string $setting): ?string
    {
        $raw = Plugin::$plugin->getSettings()->$setting;
        if ($raw === '') {
            return null;
        }
        $value = App::parseEnv($raw);
        if (!is_string($value) || trim($value) === '') {
            Craft::warning("Editor MCP: the $setting setting resolves to an empty value; using storage/editor-mcp/keys instead.", __METHOD__);
            return null;
        }
        return $value;
    }

    private function writeKeyFile(string $name, string $contents): void
    {
        $this->ensureKeyDir();
        $path = $this->keyDir() . '/' . $name;
        file_put_contents($path, $contents);
        chmod($path, 0600);
    }

    private function keyDir(): string
    {
        return Craft::$app->getPath()->getStoragePath() . '/editor-mcp/keys';
    }

    private function ensureKeyDir(): void
    {
        $dir = $this->keyDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new InvalidConfigException("Cannot create $dir");
        }
    }
}
