<?php

namespace justinholtweb\blaster\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use justinholtweb\blaster\elements\Bar;

/**
 * @method Bar[] all($db = null)
 * @method Bar|null one($db = null)
 * @method Bar|null nth(int $n, $db = null)
 */
class BarQuery extends ElementQuery
{
    public mixed $handle = null;
    public mixed $position = null;
    public mixed $priority = null;

    protected array $defaultOrderBy = [
        'blaster_bars.priority' => SORT_DESC,
        'elements.dateCreated' => SORT_DESC,
    ];

    public function handle(mixed $value): static
    {
        $this->handle = $value;

        return $this;
    }

    public function position(mixed $value): static
    {
        $this->position = $value;

        return $this;
    }

    public function priority(mixed $value): static
    {
        $this->priority = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('blaster_bars');

        $this->query->select([
            'blaster_bars.handle',
            'blaster_bars.position',
            'blaster_bars.priority',
            'blaster_bars.version',
            'blaster_bars.display as displayJson',
            'blaster_bars.targeting as targetingJson',
            'blaster_bars.schedule as scheduleJson',
            'blaster_bars.theme as themeJson',
        ]);

        if ($this->handle !== null) {
            $this->subQuery->andWhere(Db::parseParam('blaster_bars.handle', $this->handle));
        }

        if ($this->position !== null) {
            $this->subQuery->andWhere(Db::parseParam('blaster_bars.position', $this->position));
        }

        if ($this->priority !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('blaster_bars.priority', $this->priority));
        }

        return true;
    }

    /**
     * Status in SQL.
     *
     * This is why {@see \justinholtweb\blaster\models\BarSchedule}'s two coarse dates are
     * denormalised onto the table: an index that has to decode a JSON column per row to decide
     * what is live has stopped being an index.
     *
     * Note the asymmetry with a *null* date. No start date means "already started", not "never
     * starts" — so a bar with neither date is live, which is what an author who never opened the
     * schedule tab expects.
     */
    protected function statusCondition(string $status): mixed
    {
        $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

        $started = ['or', ['blaster_bars.startDate' => null], ['<=', 'blaster_bars.startDate', $now]];
        $notEnded = ['or', ['blaster_bars.endDate' => null], ['>=', 'blaster_bars.endDate', $now]];

        return match ($status) {
            Bar::STATUS_LIVE => [
                'and',
                ['elements.enabled' => true, 'elements_sites.enabled' => true],
                $started,
                $notEnded,
            ],
            Bar::STATUS_PENDING => [
                'and',
                ['elements.enabled' => true, 'elements_sites.enabled' => true],
                ['not', ['blaster_bars.startDate' => null]],
                ['>', 'blaster_bars.startDate', $now],
            ],
            Bar::STATUS_EXPIRED => [
                'and',
                ['elements.enabled' => true, 'elements_sites.enabled' => true],
                ['not', ['blaster_bars.endDate' => null]],
                ['<', 'blaster_bars.endDate', $now],
            ],
            default => parent::statusCondition($status),
        };
    }
}
