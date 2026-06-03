<?php
declare(strict_types=1);

namespace westonhancock\editormcp\services;

use Craft;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\records\OAuthClientRecord;
use yii\base\Component;

/**
 * Dynamic Client Registration with rate limiting and optional admin approval.
 *
 * Per the MCP spec, clients SHOULD support DCR. We rate-limit by source IP to
 * prevent DoS / fingerprinting via mass registration. The dcrRequireApproval
 * flag turns this into a "registration request" workflow for production.
 */
class ClientService extends Component
{
    public function dailyRegistrationsFromIp(string $ip): int
    {
        $since = (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
        return (int) OAuthClientRecord::find()
            ->where(['registeredFromIp' => $ip])
            ->andWhere(['>=', 'dateCreated', $since])
            ->count();
    }

    /**
     * Register a new client. Returns the credentials needed by the client to
     * complete OAuth: client_id (always) + client_secret (only for confidential).
     *
     * @param array{client_name?:string, redirect_uris:array, scope?:string, token_endpoint_auth_method?:string} $req
     */
    public function register(array $req, string $sourceIp): array
    {
        $settings = Plugin::$plugin->getSettings();

        if ($this->dailyRegistrationsFromIp($sourceIp) >= $settings->dcrPerIpPerDay) {
            throw new \RuntimeException('DCR rate limit exceeded for this IP');
        }

        $redirectUris = $req['redirect_uris'] ?? [];
        if (!is_array($redirectUris) || empty($redirectUris)) {
            throw new \InvalidArgumentException('redirect_uris is required');
        }
        foreach ($redirectUris as $uri) {
            $this->validateRedirectUri($uri);
        }

        $authMethod = $req['token_endpoint_auth_method'] ?? 'none';
        $isPublic = $authMethod === 'none';

        $allowedScopes = $req['scope'] ?? 'content:read';
        $scopes = array_values(array_filter(explode(' ', $allowedScopes)));
        $valid = array_intersect($scopes,
            array_keys(\westonhancock\editormcp\oauth\Repositories\ScopeRepository::SCOPES));
        if (count($valid) !== count($scopes)) {
            throw new \InvalidArgumentException('Unknown scope requested');
        }

        $clientId = 'cli_' . Uuid::uuid4()->toString();
        $secret = null;
        $secretHash = null;
        if (!$isPublic) {
            $secret = bin2hex(random_bytes(32));
            $secretHash = password_hash($secret, PASSWORD_BCRYPT);
        }

        $record = new OAuthClientRecord();
        $record->clientId = $clientId;
        $record->secretHash = $secretHash;
        $record->name = $req['client_name'] ?? 'Unnamed MCP client';
        $record->redirectUris = json_encode($redirectUris);
        $record->allowedScopes = json_encode($valid);
        $record->approved = !$settings->dcrRequireApproval;
        $record->registeredFromIp = $sourceIp;
        $record->isPublic = $isPublic;
        $record->save(false);

        return [
            'client_id' => $clientId,
            'client_secret' => $secret,
            'client_name' => $record->name,
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $authMethod,
            'scope' => implode(' ', $valid),
            'approved' => (bool) $record->approved,
        ];
    }

    private function validateRedirectUri(string $uri): void
    {
        $settings = Plugin::$plugin->getSettings();
        $parsed = parse_url($uri);
        if (!$parsed || !isset($parsed['scheme'])) {
            throw new \InvalidArgumentException("Invalid redirect_uri: $uri");
        }
        $isDev = Craft::$app->env === 'dev';
        $allowed = $settings->allowedRedirectSchemes;
        if ($isDev) {
            $allowed = array_unique(array_merge($allowed, ['http']));
        }
        // localhost loopback is OK with http per RFC 8252 even in non-dev
        $isLoopback = isset($parsed['host'])
            && in_array($parsed['host'], ['localhost', '127.0.0.1', '::1'], true);
        if (!in_array($parsed['scheme'], $allowed, true) && !($parsed['scheme'] === 'http' && $isLoopback)) {
            throw new \InvalidArgumentException("redirect_uri scheme not allowed: {$parsed['scheme']}");
        }
        foreach ($settings->redirectUriPatterns as $pattern) {
            if (!@preg_match($pattern, $uri)) {
                throw new \InvalidArgumentException("redirect_uri does not match required pattern");
            }
        }
    }

    public function approve(int $id): void
    {
        $record = OAuthClientRecord::findOne($id);
        if ($record) {
            $record->approved = true;
            $record->save(false);
        }
    }

    public function revoke(int $id): void
    {
        $record = OAuthClientRecord::findOne($id);
        if ($record) {
            $record->revoked = true;
            $record->save(false);
            Plugin::$plugin->tokens->revokeAllForClient($id);
        }
    }
}
