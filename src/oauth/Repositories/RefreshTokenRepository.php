<?php

declare(strict_types=1);

namespace westonhancock\editormcp\oauth\Repositories;

use DateTimeImmutable;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use westonhancock\editormcp\oauth\Entities\RefreshTokenEntity;
use westonhancock\editormcp\records\AccessTokenRecord;
use westonhancock\editormcp\records\RefreshTokenRecord;

/**
 * Refresh token storage with rotation + theft detection.
 *
 * On refresh, the previous token is marked consumed. If a consumed token is
 * presented again, every refresh token in the chain (walked via parentId) is
 * revoked along with its associated access tokens. RFC 6749 §10.4.
 */
class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $accessToken = $refreshTokenEntity->getAccessToken();
        $accessRecord = AccessTokenRecord::findOne(['tokenId' => $accessToken->getIdentifier()]);

        $record = new RefreshTokenRecord();
        $record->tokenId = $refreshTokenEntity->getIdentifier();
        $record->tokenHash = hash('sha256', $refreshTokenEntity->getIdentifier());
        $record->accessTokenId = $accessRecord?->id;
        $record->clientId = $accessRecord?->clientId
            ?? throw new \RuntimeException('Access token not found when persisting refresh token');
        $record->userId = (int) $accessRecord->userId;
        $record->scopes = json_encode(array_map(static fn($s) => $s->getIdentifier(),
            $accessToken->getScopes()));
        $record->expiresAt = $refreshTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s');
        $record->save(false);
    }

    /**
     * Called by League's RefreshTokenGrant after a successful rotation.
     * Marks the token "consumed" but deliberately leaves revokedAt null so a
     * subsequent replay of the same token can be detected as theft (see
     * isRefreshTokenRevoked below). For explicit revocation paths (the
     * /oauth/revoke endpoint, kill switch, admin action) use forceRevoke().
     *
     * @param string $tokenId
     */
    public function revokeRefreshToken($tokenId): void
    {
        $record = RefreshTokenRecord::findOne(['tokenId' => (string) $tokenId]);
        if (!$record) {
            return;
        }
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $record->consumedAt = $record->consumedAt ?? $now;
        $record->save(false);
    }

    /**
     * Forcibly revoke a refresh token (and any access token paired with it).
     * For the explicit revoke endpoint or admin actions where the intent is
     * "this token is dead", not "this token was consumed by legitimate rotation".
     */
    public function forceRevoke(string $tokenId): void
    {
        $record = RefreshTokenRecord::findOne(['tokenId' => $tokenId]);
        if (!$record) {
            return;
        }
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $record->revokedAt = $now;
        $record->consumedAt = $record->consumedAt ?? $now;
        $record->save(false);
        if ($record->accessTokenId) {
            AccessTokenRecord::updateAll(
                ['revokedAt' => $now],
                ['id' => $record->accessTokenId, 'revokedAt' => null],
            );
        }
    }

    /** @param string $tokenId */
    public function isRefreshTokenRevoked($tokenId): bool
    {
        $record = RefreshTokenRecord::findOne(['tokenId' => (string) $tokenId]);
        if (!$record) {
            return true;
        }
        // Theft detection: if a consumed token is presented, revoke the whole chain.
        if ($record->consumedAt !== null && $record->revokedAt === null) {
            $this->revokeChain($record);
            return true;
        }
        return $record->revokedAt !== null;
    }

    private function revokeChain(RefreshTokenRecord $start): void
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $cursor = $start;
        $seen = [];
        while ($cursor && !isset($seen[$cursor->id])) {
            $seen[$cursor->id] = true;
            $cursor->revokedAt = $now;
            $cursor->save(false);
            if ($cursor->accessTokenId) {
                AccessTokenRecord::updateAll(
                    ['revokedAt' => $now],
                    ['id' => $cursor->accessTokenId],
                );
            }
            $cursor = $cursor->parentId
                ? RefreshTokenRecord::findOne(['id' => $cursor->parentId])
                : null;
        }
        // Forward: revoke children too
        $children = RefreshTokenRecord::findAll(['parentId' => $start->id]);
        foreach ($children as $child) {
            if (!isset($seen[$child->id])) {
                $this->revokeChain($child);
            }
        }
    }
}
