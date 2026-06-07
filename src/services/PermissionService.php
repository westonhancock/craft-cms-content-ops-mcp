<?php

declare(strict_types=1);

namespace westonhancock\editormcp\services;

use Craft;
use craft\elements\User;
use yii\base\Component;

/**
 * Authorization composition for the plugin:
 *
 *   OAuth scope  ∧  Craft permission  →  allow
 *
 * Scopes are checked from the bearer token's grant. Craft permissions are
 * checked by running the operation as the resolved user (see ImpersonationService).
 *
 * This service handles the *scope* half. The Craft-permission half is enforced
 * naturally by Craft when element queries / element saves run as the user.
 */
class PermissionService extends Component
{
    /** Tool-name → scope required to invoke it */
    public const TOOL_SCOPES = [
        // Discovery — read scope
        'list_sections' => 'content:read',
        'list_section_fields' => 'content:read',
        'list_asset_volumes' => 'content:read',
        // Entries
        'find_entries' => 'content:read',
        'get_entry' => 'content:read',
        'create_entry' => 'content:write',
        'update_entry_fields' => 'content:write',
        'set_entry_status' => 'content:publish',
        'delete_entry' => 'content:delete',
        // Assets
        'find_assets' => 'content:read',
        'get_asset' => 'content:read',
        'upload_asset' => 'assets:write',
        // Categories / Globals
        'list_categories' => 'content:read',
        'get_global' => 'content:read',
        // Meta
        'who_am_i' => null,  // no scope required
    ];

    public function requiredScope(string $tool): ?string
    {
        // array_key_exists, not ??, because the map stores null for tools that
        // don't require a scope (e.g. who_am_i) — ?? would fall through on null.
        if (!array_key_exists($tool, self::TOOL_SCOPES)) {
            throw new \InvalidArgumentException("Unknown tool: $tool");
        }
        return self::TOOL_SCOPES[$tool];
    }

    public function hasScope(array $grantedScopes, string $tool): bool
    {
        $required = $this->requiredScope($tool);
        if ($required === null) {
            return true;
        }
        return in_array($required, $grantedScopes, true);
    }

    /**
     * High-stakes scopes require prompt=login on the authorize request.
     */
    public function isHighStakes(string $scope): bool
    {
        $settings = \westonhancock\editormcp\Plugin::$plugin->getSettings();
        return in_array($scope, $settings->highStakesScopes, true);
    }

    public function loadUser(int $userId): ?User
    {
        $user = Craft::$app->getUsers()->getUserById($userId);
        if (!$user || $user->suspended || $user->pending || $user->locked) {
            return null;
        }
        return $user;
    }
}
