<?php

namespace justinholtweb\blaster\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use DateTimeZone;
use justinholtweb\blaster\Plugin;
use justinholtweb\blaster\records\BarRecord;
use justinholtweb\blaster\records\StatRecord;
use Throwable;
use yii\db\Expression;

/**
 * Impression, click and dismissal counts.
 *
 * The whole of what Blaster remembers about visitors is three integers per bar, per site, per
 * day. There is no row per event, no identifier, no address and no session — which is not a
 * privacy feature bolted on afterwards so much as the reason the aggregate shape was chosen: a
 * counter that cannot be joined back to a person cannot leak one.
 *
 * The consequence to be aware of is that the numbers cannot answer "how many *people*", only
 * "how many times". That is the right trade for a notification bar.
 */
class Stats extends Component
{
    public const TYPE_VIEW = 'view';
    public const TYPE_CLICK = 'click';
    public const TYPE_DISMISS = 'dismiss';

    private const COLUMNS = [
        self::TYPE_VIEW => 'views',
        self::TYPE_CLICK => 'clicks',
        self::TYPE_DISMISS => 'dismissals',
    ];

    /** @var array<int, array{views: int, clicks: int, dismissals: int}>|null */
    private ?array $totals = null;

    public static function isValidType(string $type): bool
    {
        return isset(self::COLUMNS[$type]);
    }

    /**
     * Adds one to a counter.
     *
     * Never throws: this is called from a fire-and-forget beacon on a page a visitor is usually
     * in the middle of leaving, and a failed count is not worth a 500 in anyone's error log.
     */
    public function record(int $barId, int $siteId, string $type): bool
    {
        if (!self::isValidType($type) || !Plugin::getInstance()->getSettings()->trackStats) {
            return false;
        }

        $column = self::COLUMNS[$type];
        $date = (new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d');

        try {
            Craft::$app->getDb()->createCommand()->upsert(StatRecord::TABLE, [
                'barId' => $barId,
                'siteId' => $siteId,
                'date' => $date,
                $column => 1,
                'uid' => StringHelper::UUID(),
            ], [
                $column => new Expression("[[$column]] + 1"),
            ])->execute();
        } catch (Throwable $e) {
            Craft::warning("Could not record a $type for bar $barId: " . $e->getMessage(), 'blaster');

            return false;
        }

        $this->totals = null;

        return true;
    }

    /**
     * Lifetime totals for one bar.
     *
     * Loads every bar's totals on first use rather than one bar's, because the caller is almost
     * always the element index asking three attributes of each of N rows — the per-bar query it
     * looks like would be 3N round trips for a screen showing a handful of numbers.
     *
     * @return array{views: int, clicks: int, dismissals: int}
     */
    public function totalsForBar(int $barId, ?int $siteId = null): array
    {
        if ($siteId !== null) {
            return $this->loadTotals($siteId)[$barId] ?? ['views' => 0, 'clicks' => 0, 'dismissals' => 0];
        }

        $this->totals ??= $this->loadTotals();

        return $this->totals[$barId] ?? ['views' => 0, 'clicks' => 0, 'dismissals' => 0];
    }

    /** @return array<int, array{views: int, clicks: int, dismissals: int}> */
    private function loadTotals(?int $siteId = null): array
    {
        $query = (new Query())
            ->select([
                'barId',
                'views' => new Expression('SUM([[views]])'),
                'clicks' => new Expression('SUM([[clicks]])'),
                'dismissals' => new Expression('SUM([[dismissals]])'),
            ])
            ->from(StatRecord::TABLE)
            ->groupBy(['barId']);

        if ($siteId !== null) {
            $query->where(['siteId' => $siteId]);
        }

        $totals = [];

        foreach ($query->all() as $row) {
            $totals[(int)$row['barId']] = [
                'views' => (int)$row['views'],
                'clicks' => (int)$row['clicks'],
                'dismissals' => (int)$row['dismissals'],
            ];
        }

        return $totals;
    }

    /**
     * A day-by-day series with no gaps, oldest first.
     *
     * Days with no activity are filled in as zeros rather than omitted, because a chart or table
     * that silently skips quiet days reads as continuous activity.
     *
     * @return array<int, array{date: string, views: int, clicks: int, dismissals: int}>
     */
    public function series(int $barId, int $days = 30, ?int $siteId = null): array
    {
        $days = max(1, min($days, 730));
        $zone = new DateTimeZone(Craft::$app->getTimeZone());
        $start = (new DateTime('now', $zone))->sub(new DateInterval('P' . ($days - 1) . 'D'));

        $query = (new Query())
            ->select(['date', 'views' => new Expression('SUM([[views]])'), 'clicks' => new Expression('SUM([[clicks]])'), 'dismissals' => new Expression('SUM([[dismissals]])')])
            ->from(StatRecord::TABLE)
            ->where(['barId' => $barId])
            ->andWhere(['>=', 'date', $start->format('Y-m-d')])
            ->groupBy(['date']);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $rows = [];

        foreach ($query->all() as $row) {
            // MySQL hands back `Y-m-d`, Postgres can include a time; normalise before keying.
            $rows[substr((string)$row['date'], 0, 10)] = $row;
        }

        $series = [];
        $cursor = clone $start;

        for ($i = 0; $i < $days; $i++) {
            $key = $cursor->format('Y-m-d');
            $row = $rows[$key] ?? [];

            $series[] = [
                'date' => $key,
                'views' => (int)($row['views'] ?? 0),
                'clicks' => (int)($row['clicks'] ?? 0),
                'dismissals' => (int)($row['dismissals'] ?? 0),
            ];

            $cursor->add(new DateInterval('P1D'));
        }

        return $series;
    }

    /** Deletes daily rows older than the retention setting. Returns how many went. */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->statsRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())))
            ->sub(new DateInterval("P{$days}D"))
            ->format('Y-m-d');

        $this->totals = null;

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(StatRecord::TABLE, ['<', 'date', $cutoff])
            ->execute();
    }

    /**
     * Drops counters for bars that no longer exist.
     *
     * The foreign key already cascades a hard delete, so this catches the case it cannot: rows
     * left behind when a bar's row was removed out from under the database — a restored backup,
     * a botched merge, a table truncated by hand.
     */
    public function collectGarbage(): int
    {
        $orphans = (new Query())
            ->select(['s.id'])
            ->from(['s' => StatRecord::TABLE])
            ->leftJoin(['b' => BarRecord::TABLE], '[[b.id]] = [[s.barId]]')
            ->where(['b.id' => null])
            ->column();

        if (!$orphans) {
            return 0;
        }

        $this->totals = null;

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(StatRecord::TABLE, ['id' => $orphans])
            ->execute();
    }
}
