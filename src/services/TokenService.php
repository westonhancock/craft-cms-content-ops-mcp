<?php
declare(strict_types=1);

namespace westonhancock\editormcp\services;

use Craft;
use DateInterval;
use DateTimeImmutable;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\oauth\Repositories\AccessTokenRepository;
use westonhancock\editormcp\oauth\Repositories\AuthCodeRepository;
use westonhancock\editormcp\oauth\Repositories\ClientRepository;
use westonhancock\editormcp\oauth\Repositories\RefreshTokenRepository;
use westonhancock\editormcp\oauth\Repositories\ScopeRepository;
use westonhancock\editormcp\oauth\Repositories\UserRepository;
use westonhancock\editormcp\records\AccessTokenRecord;
use westonhancock\editormcp\records\RefreshTokenRecord;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Owns the league/oauth2-server instance and key material.
 *
 * Keys live on disk under storage/editor-mcp/keys (file mode 0600). If they
 * don't exist, init() generates them. Encryption secret is stored in
 * Craft's app config-backed parameter store via Craft::parseEnv.
 */
class TokenService extends Component
{
    public const ACCESS_TTL_MAX = 3600;

    private ?AuthorizationServer $authServer = null;
    private ?ResourceServer $resourceServer = null;

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

    private function privateKey(): CryptKey
    {
        return new CryptKey($this->keyPath('private.key'), null, false);
    }

    private function publicKey(): CryptKey
    {
        return new CryptKey($this->keyPath('public.key'), null, false);
    }

    private function encryptionKey(): string
    {
        $path = $this->keyDir() . '/encryption.key';
        if (!is_file($path)) {
            $this->ensureKeyDir();
            file_put_contents($path, base64_encode(random_bytes(32)));
            chmod($path, 0600);
        }
        return trim((string) file_get_contents($path));
    }

    private function keyPath(string $name): string
    {
        $path = $this->keyDir() . '/' . $name;
        if (!is_file($path)) {
            $this->generateKeyPair();
        }
        return $path;
    }

    private function generateKeyPair(): void
    {
        $this->ensureKeyDir();
        $res = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (!$res) {
            throw new InvalidConfigException('Failed to generate OAuth key pair');
        }
        openssl_pkey_export($res, $priv);
        $pub = openssl_pkey_get_details($res)['key'];
        $dir = $this->keyDir();
        file_put_contents($dir . '/private.key', $priv);
        chmod($dir . '/private.key', 0600);
        file_put_contents($dir . '/public.key', $pub);
        chmod($dir . '/public.key', 0644);
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
