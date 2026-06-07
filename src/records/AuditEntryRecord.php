<?php

declare(strict_types=1);

namespace westonhancock\editormcp\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $requestId
 * @property int|null $userId
 * @property int|null $clientId
 * @property int|null $tokenId
 * @property string|null $tool
 * @property string|null $scopes JSON-encoded array
 * @property string|null $paramsStructural JSON
 * @property string|null $paramsVerbose JSON
 * @property string $status success|denied|error|rate-limited
 * @property string|null $errorCode
 * @property string|null $errorMessage
 * @property string|null $ipAddress
 * @property string|null $userAgent
 * @property int|null $durationMs
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class AuditEntryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%editormcp_audit_entries}}';
    }
}
