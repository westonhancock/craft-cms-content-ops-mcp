<?php

declare(strict_types=1);

namespace westonhancock\editormcp\oauth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use westonhancock\editormcp\oauth\Entities\ClientEntity;
use westonhancock\editormcp\records\OAuthClientRecord;

class ClientRepository implements ClientRepositoryInterface
{
    /** @param string $clientIdentifier */
    public function getClientEntity($clientIdentifier): ?ClientEntityInterface
    {
        $record = OAuthClientRecord::findOne(['clientId' => (string) $clientIdentifier]);
        if (!$record || $record->revoked) {
            return null;
        }
        return $this->toEntity($record);
    }

    /**
     * @param string $clientIdentifier
     * @param string|null $clientSecret
     * @param string|null $grantType
     */
    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        $record = OAuthClientRecord::findOne(['clientId' => (string) $clientIdentifier]);
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
        return password_verify((string) $clientSecret, $record->secretHash);
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
