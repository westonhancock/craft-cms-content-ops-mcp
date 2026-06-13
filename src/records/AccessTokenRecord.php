<?php

declare(strict_types=1);

namespace westonhancock\editormcp\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $tokenId
 * @property int $clientId
 * @property int $userId
 * @property string $scopes JSON-encoded array
 * @property string $expiresAt
 * @property string|null $revokedAt
 * @property string|null $lastUsedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class AccessTokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%editormcp_access_tokens}}';
    }
}
