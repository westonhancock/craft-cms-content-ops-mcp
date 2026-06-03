<?php
declare(strict_types=1);

namespace westonhancock\editormcp\records;

use craft\db\ActiveRecord;

class AuditEntryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%editormcp_audit_entries}}';
    }
}
