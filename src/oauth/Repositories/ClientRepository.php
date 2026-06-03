<?php
declare(strict_types=1);

namespace westonhancock\editormcp\oauth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use westonhancock\editormcp\oauth\Entities\ClientEntity;
use westonhancock\editormcp\records\OAuthClientRecord;

class ClientRepository implements ClientRepositoryInterface
{
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $record = OAuthClientRecord::findOne(['clientId' => $clientIdentifier]);
        if (!$record || $record->revoked) {
            return null;
        }
        return $this->toEntity($record);
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $record = OAuthClientRecord::findOne(['clientId' => $clientIdentifier]);
        if (!$record || $record->revoked) {
            return false;
        }
        // Public clients (no secretHash): PKCE-only. League OAuth handles PKCE validation separately.
        if ($record->secretHash === null) {
            return $clientSecret === null || $clientSecret === '';
        }
        if ($clientSecret === null) {
            return false;
        }
        return password_verify($clientSecret, $record->secretHash);
    }

    private function toEntity(OAuthClientRecord $record): ClientEntity
    {
        $entity = new ClientEntity();
        $entity->setIdentifier($record->clientId);
        $entity->setName($record->name);
        $entity->setRedirectUri(json_decode($record->redirectUris, true) ?? []);
        $entity->setConfidential($record->isPublic ? false : true);
        $entity->internalId = (int) $record->id;
        return $entity;
    }
}
