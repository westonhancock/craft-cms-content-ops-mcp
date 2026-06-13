<?php

declare(strict_types=1);

namespace westonhancock\editormcp\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $clientId
 * @property string|null $secretHash
 * @property string $name
 * @property string $redirectUris JSON-encoded array
 * @property string $allowedScopes JSON-encoded array
 * @property bool $approved
 * @property string|null $registeredFromIp
 * @property bool $isPublic
 * @property bool $revoked
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class OAuthClientRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%editormcp_clients}}';
    }
}
