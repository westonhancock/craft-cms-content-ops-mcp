<?php

declare(strict_types=1);

namespace westonhancock\editormcp\models;

use craft\base\Model;

/**
 * Plugin settings. Persisted via craft\base\Plugin::getSettings().
 *
 * Production-safety invariants:
 *  - enabled defaults to false so a fresh install in prod is inert
 *  - HTTPS required outside of dev (enforced in OAuthController + McpController)
 *  - Access token TTL is capped at 3600 regardless of config — see TokenService::ACCESS_TTL_MAX
 */
class Settings extends Model
{
    public bool $enabled = false;

    /** seconds; capped at 3600 by TokenService */
    public int $accessTokenTtl = 3600;

    /** seconds; default 30 days */
    public int $refreshTokenTtl = 2592000;

    /** seconds; default 10 minutes */
    public int $authCodeTtl = 600;

    /** Per-IP daily limit for Dynamic Client Registration */
    public int $dcrPerIpPerDay = 10;

    /** If true, newly DCR-registered clients are quarantined until an admin approves */
    public bool $dcrRequireApproval = false;

    /** Allowed redirect URI schemes. http only allowed in dev */
    public array $allowedRedirectSchemes = ['https'];

    /** Optional regex patterns each redirect_uri must match (empty = no extra restriction) */
    public array $redirectUriPatterns = [];

    /** Per-user requests per minute for MCP endpoint */
    public int $rateLimitPerUserPerMinute = 60;

    /** Audit log retention (days). null = never delete */
    public ?int $auditRetentionDays = 90;

    /** Log structural params only by default; verbose mode logs values (NEVER in prod) */
    public bool $auditVerbose = false;

    /** Slack/PagerDuty webhook for security events. Empty = disabled */
    public string $securityWebhookUrl = '';

    /** Max base64 upload payload (bytes, raw). 25MB default. */
    public int $assetUploadMaxBytes = 26_214_400;

    /** Allowed MIME types for uploads; empty = inherit per-volume Craft config */
    public array $assetMimeAllowlist = [];

    /** Force prompt=login for these scopes regardless of session state */
    public array $highStakesScopes = ['content:publish', 'content:delete'];

    /** Optional IP allowlist (CIDR). Empty = no restriction */
    public array $ipAllowlist = [];

    /** Kill switch: when true, all OAuth + MCP endpoints return 503 */
    public bool $killSwitch = false;

    public function defineRules(): array
    {
        return [
            [['enabled', 'dcrRequireApproval', 'auditVerbose', 'killSwitch'], 'boolean'],
            [['accessTokenTtl', 'refreshTokenTtl', 'authCodeTtl', 'dcrPerIpPerDay',
              'rateLimitPerUserPerMinute', 'assetUploadMaxBytes', ], 'integer', 'min' => 1],
            [['accessTokenTtl'], 'integer', 'max' => 3600],
            [['auditRetentionDays'], 'integer', 'min' => 1, 'skipOnEmpty' => true],
            [['securityWebhookUrl'], 'url', 'skipOnEmpty' => true],
        ];
    }
}
