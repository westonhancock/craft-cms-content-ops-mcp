<?php

declare(strict_types=1);

namespace westonhancock\editormcp\oauth\Repositories;

use DateTimeImmutable;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use westonhancock\editormcp\oauth\Entities\AuthCodeEntity;
use westonhancock\editormcp\records\AuthCodeRecord;
use westonhancock\editormcp\records\OAuthClientRecord;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $clientRecord = OAuthClientRecord::findOne([
            'clientId' => $authCodeEntity->getClient()->getIdentifier(),
        ]);

        $record = new AuthCodeRecord();
        $record->codeId = $authCodeEntity->getIdentifier();
        $record->clientId = (int) $clientRecord->id;
        $record->userId = (int) $authCodeEntity->getUserIdentifier();
        $record->redirectUri = (string) $authCodeEntity->getRedirectUri();
        $record->scopes = json_encode(array_map(static fn($s) => $s->getIdentifier(),
            $authCodeEntity->getScopes()));
        // PKCE challenge — set externally on the entity by the grant; not in the interface.
        $record->codeChallenge = $authCodeEntity->codeChallenge ?? '';
        $record->codeChallengeMethod = $authCodeEntity->codeChallengeMethod ?? 'S256';
        $record->forcedFreshLogin = (bool) ($authCodeEntity->forcedFreshLogin ?? false);
        $record->expiresAt = $authCodeEntity->getExpiryDateTime()->format('Y-m-d H:i:s');
        $record->save(false);
    }

    /** @param string $codeId */
    public function revokeAuthCode($codeId): void
    {
        $record = AuthCodeRecord::findOne(['codeId' => (string) $codeId]);
        if (!$record) {
            return;
        }
        $record->consumedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $record->save(false);
    }

    /** @param string $codeId */
    public function isAuthCodeRevoked($codeId): bool
    {
        $record = AuthCodeRecord::findOne(['codeId' => (string) $codeId]);
        return !$record || $record->consumedAt !== null;
    }
}
