<?php

declare(strict_types=1);

namespace westonhancock\editormcp\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $tokenId
 * @property string $tokenHash
 * @property int|null $accessTokenId
 * @property int $clientId
 * @property int $userId
 * @property string $scopes JSON-encoded array
 * @property string $expiresAt
 * @property string|null $revokedAt
 * @property int|null $parentId
 * @property string|null $consumedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class RefreshTokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%editormcp_refresh_tokens}}';
    }
}
