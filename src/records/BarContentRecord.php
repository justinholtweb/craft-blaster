<?php

namespace justinholtweb\blaster\records;

use craft\db\ActiveRecord;

/**
 * What a bar *says* on one site.
 *
 * @property int $id
 * @property int $siteId
 * @property string|null $message
 * @property bool $buttonEnabled
 * @property string|null $buttonLabel
 * @property string|null $buttonUrl
 * @property bool $buttonNewWindow
 */
class BarContentRecord extends ActiveRecord
{
    public const TABLE = '{{%blaster_bar_content}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }

    public static function primaryKey(): array
    {
        return ['id', 'siteId'];
    }
}
