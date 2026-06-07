<?php

declare(strict_types=1);

namespace westonhancock\editormcp\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $codeId
 * @property int $clientId
 * @property int $userId
 * @property string $redirectUri
 * @property string $scopes JSON-encoded array
 * @property string $codeChallenge
 * @property string $codeChallengeMethod
 * @property bool $forcedFreshLogin
 * @property string $expiresAt
 * @property string|null $consumedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class AuthCodeRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%editormcp_auth_codes}}';
    }
}
