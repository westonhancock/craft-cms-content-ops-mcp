<?php

declare(strict_types=1);

namespace westonhancock\editormcp\events;

use craft\elements\User;
use craft\events\ModelEvent;
use westonhancock\editormcp\Plugin;
use yii\base\Event;

/**
 * Revoke MCP tokens when a user becomes ineligible.
 *
 * Revocation triggers (per the plan):
 *   - suspended flips on
 *   - pending flips on  (treated same as suspended for safety)
 *   - locked flips on
 *   - the user is deleted
 *
 * Group/permission changes don't revoke (per-call permission checks catch
 * those at runtime), but they are logged as security events.
 */
class UserLifecycleHandler
{
    public static function onAfterSave(ModelEvent $event): void
    {
        /** @var User $user */
        $user = $event->sender;
        if ($user->suspended || $user->pending || $user->locked) {
            Plugin::$plugin->tokens->revokeAllForUser($user->id);
            Plugin::$plugin->security->notify('user_tokens_revoked', [
                'userId' => (int) $user->id,
                'reason' => $user->suspended ? 'suspended' : ($user->locked ? 'locked' : 'pending'),
            ]);
            Plugin::$plugin->audit->log([
                'requestId' => bin2hex(random_bytes(8)),
                'userId' => $user->id,
                'tool' => null,
                'status' => 'denied',
                'errorCode' => 'user_revoked',
                'errorMessage' => 'User became inactive — tokens revoked',
            ]);
        }
    }

    public static function onBeforeDelete(Event $event): void
    {
        /** @var User $user */
        $user = $event->sender;
        Plugin::$plugin->tokens->revokeAllForUser($user->id);
        Plugin::$plugin->security->notify('user_tokens_revoked', [
            'userId' => (int) $user->id,
            'reason' => 'deleted',
        ]);
        Plugin::$plugin->audit->log([
            'requestId' => bin2hex(random_bytes(8)),
            'userId' => $user->id,
            'tool' => null,
            'status' => 'denied',
            'errorCode' => 'user_deleted',
            'errorMessage' => 'User deleted — tokens revoked',
        ]);
    }
}
