<?php

declare(strict_types=1);

namespace westonhancock\editormcp\services;

use Craft;
use westonhancock\editormcp\Plugin;
use yii\base\Component;

/**
 * Delivers security events to the configured webhook (Settings::$securityWebhookUrl).
 *
 * Events fired:
 *   - refresh_token_theft_detected — a consumed refresh token was replayed
 *   - dcr_rate_limited             — an IP exceeded the DCR registration cap
 *   - rate_limit_anomaly           — a user hit the MCP rate limit 5+ times in an hour
 *   - user_tokens_revoked          — lifecycle revocation (suspend/lock/pending/delete)
 *   - kill_switch_activated        — an admin flipped the kill switch on
 *
 * The payload is generic JSON with a Slack-compatible top-level `text` field,
 * so a Slack incoming webhook renders it readably with zero config. Delivery
 * is best-effort: failures are logged, never thrown — a security notification
 * outage must not break the request that triggered it.
 */
class SecurityEventService extends Component
{
    public function notify(string $event, array $context = []): void
    {
        $url = Plugin::$plugin->getSettings()->securityWebhookUrl;
        if ($url === '') {
            return;
        }

        $site = Craft::$app->getSites()->getPrimarySite();
        $contextText = $context !== []
            ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES)
            : '';

        try {
            Craft::createGuzzleClient(['timeout' => 5])->post($url, [
                'json' => [
                    'source' => 'craft-editor-mcp',
                    'event' => $event,
                    'site' => $site->getBaseUrl() ?? $site->name,
                    'occurredAt' => date('c'),
                    'context' => $context,
                    'text' => "[editor-mcp] security event: {$event}{$contextText}",
                ],
            ]);
        } catch (\Throwable $e) {
            Craft::warning(
                "Security webhook delivery failed for '$event': {$e->getMessage()}",
                'editor-mcp',
            );
        }
    }
}
