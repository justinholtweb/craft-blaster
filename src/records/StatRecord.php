<?php

namespace justinholtweb\blaster\records;

use craft\db\ActiveRecord;

/**
 * One day of counters for one bar on one site.
 *
 * Deliberately the whole of what Blaster remembers about visitors: three integers and a date.
 * There is no row per impression, no identifier, and nothing that could be joined back to a
 * person.
 *
 * @property int $id
 * @property int $barId
 * @property int $siteId
 * @property string $date
 * @property int $views
 * @property int $clicks
 * @property int $dismissals
 */
class StatRecord extends ActiveRecord
{
    public const TABLE = '{{%blaster_stats}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
