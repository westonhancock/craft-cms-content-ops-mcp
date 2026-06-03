<?php
declare(strict_types=1);

namespace westonhancock\editormcp\records;

use craft\db\ActiveRecord;

class AuthCodeRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%editormcp_auth_codes}}';
    }
}
