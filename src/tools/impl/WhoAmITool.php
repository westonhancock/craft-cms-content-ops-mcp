<?php
declare(strict_types=1);

namespace westonhancock\editormcp\tools\impl;

use Craft;
use westonhancock\editormcp\Plugin;
use westonhancock\editormcp\tools\Tool;

class WhoAmITool implements Tool
{
    public function name(): string { return 'who_am_i'; }

    public function description(): string
    {
        return 'Returns the connected Craft user identity and the scopes granted to this session. Useful for understanding the constraints of the current connection.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function execute(array $args): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        $groups = [];
        foreach ($user?->getGroups() ?? [] as $g) {
            $groups[] = $g->handle;
        }
        return [
            'userId' => $user?->id,
            'username' => $user?->username,
            'email' => $user?->email,
            'admin' => (bool) $user?->admin,
            'groups' => $groups,
            // Echoed so the AI knows its grant without parsing JWTs.
            'scopes' => Plugin::$plugin->toolRegistry->currentTokenClaims['scopes'] ?? [],
        ];
    }
}
