<?php

namespace justinholtweb\blaster\records;

use craft\db\ActiveRecord;

/**
 * What a bar *is* — the parts that do not vary by site.
 *
 * @property int $id
 * @property string $handle
 * @property string $position
 * @property int $priority
 * @property int $version
 * @property string|null $display
 * @property string|null $targeting
 * @property string|null $schedule
 * @property string|null $theme
 * @property string|null $startDate
 * @property string|null $endDate
 */
class BarRecord extends ActiveRecord
{
    public const TABLE = '{{%blaster_bars}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
