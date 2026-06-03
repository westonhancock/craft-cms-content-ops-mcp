<?php
declare(strict_types=1);

namespace westonhancock\editormcp\oauth\Repositories;

use DateTimeImmutable;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use westonhancock\editormcp\oauth\Entities\AccessTokenEntity;
use westonhancock\editormcp\oauth\Entities\ClientEntity;
use westonhancock\editormcp\records\AccessTokenRecord;
use westonhancock\editormcp\records\OAuthClientRecord;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        int|string|null $userIdentifier = null,
    ): AccessTokenEntityInterface {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        if ($userIdentifier !== null) {
            $token->setUserIdentifier($userIdentifier);
        }
        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        /** @var ClientEntity $client */
        $client = $accessTokenEntity->getClient();
        $clientRecord = OAuthClientRecord::findOne(['clientId' => $client->getIdentifier()]);
        if (!$clientRecord) {
            throw new \RuntimeException('Client not found when persisting access token');
        }

        $record = new AccessTokenRecord();
        $record->tokenId = $accessTokenEntity->getIdentifier();
        $record->clientId = (int) $clientRecord->id;
        $record->userId = (int) $accessTokenEntity->getUserIdentifier();
        $record->scopes = json_encode(array_map(static fn($s) => $s->getIdentifier(),
            $accessTokenEntity->getScopes()));
        $record->expiresAt = $accessTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s');
        $record->save(false);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $record = AccessTokenRecord::findOne(['tokenId' => $tokenId]);
        if ($record) {
            $record->revokedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $record->save(false);
        }
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $record = AccessTokenRecord::findOne(['tokenId' => $tokenId]);
        return !$record || $record->revokedAt !== null;
    }
}
