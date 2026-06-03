<?php
declare(strict_types=1);

namespace westonhancock\editormcp\oauth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use westonhancock\editormcp\oauth\Entities\ScopeEntity;

/**
 * The five capability-grouped scopes the plugin exposes. Editors see these
 * on the consent screen, not the 14 tool names.
 */
class ScopeRepository implements ScopeRepositoryInterface
{
    public const SCOPES = [
        'content:read' => 'Read entries, assets, categories, and globals you have access to.',
        'content:write' => 'Create entries and update entry fields (does not publish).',
        'content:publish' => 'Change entry status (publish, unpublish, disable).',
        'content:delete' => 'Delete entries.',
        'assets:write' => 'Upload, replace, or delete assets.',
    ];

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        if (!array_key_exists($identifier, self::SCOPES)) {
            return null;
        }
        $scope = new ScopeEntity();
        $scope->setIdentifier($identifier);
        return $scope;
    }

    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        // Filter to scopes the client was registered for.
        $allowed = $clientEntity instanceof \westonhancock\editormcp\oauth\Entities\ClientEntity
            ? $this->clientAllowedScopes($clientEntity)
            : array_keys(self::SCOPES);

        return array_values(array_filter($scopes, static fn(ScopeEntityInterface $s) =>
            in_array($s->getIdentifier(), $allowed, true)
        ));
    }

    private function clientAllowedScopes(\westonhancock\editormcp\oauth\Entities\ClientEntity $client): array
    {
        $record = \westonhancock\editormcp\records\OAuthClientRecord::findOne(['id' => $client->internalId]);
        if (!$record) {
            return [];
        }
        return json_decode($record->allowedScopes, true) ?? [];
    }
}
