<?php

namespace justinholtweb\blaster\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use justinholtweb\blaster\elements\Bar;
use justinholtweb\blaster\elements\db\BarQuery;
use Throwable;

/**
 * The bar library: reading, saving, deleting, and the authority on handles.
 */
class Bars extends Component
{
    /** @var array<int, array<int|string, Bar|null>> */
    private array $byId = [];

    public function getBarById(int $id, ?int $siteId = null): ?Bar
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        return $this->byId[$id][$siteId] ??= Bar::find()
            ->id($id)
            ->siteId($siteId)
            ->status(null)
            ->one();
    }

    public function getBarByHandle(string $handle, ?int $siteId = null): ?Bar
    {
        return Bar::find()
            ->handle($handle)
            ->siteId($siteId ?? Craft::$app->getSites()->getCurrentSite()->id)
            ->status(null)
            ->one();
    }

    /** @return Bar[] */
    public function getAllBars(?int $siteId = null): array
    {
        return Bar::find()
            ->siteId($siteId ?? Craft::$app->getSites()->getCurrentSite()->id)
            ->status(null)
            ->orderBy(['blaster_bars.priority' => SORT_DESC, 'elements.dateCreated' => SORT_DESC])
            ->all();
    }

    /**
     * Bars that are enabled and inside their date range on this site, highest priority first.
     *
     * Everything finer than the date range — days of the week, the daily window, the URI, who is
     * signed in — is {@see Matcher}'s job. This is the cheap query that narrows the field first.
     *
     * @return Bar[]
     */
    public function getLiveBars(int $siteId): array
    {
        /** @var BarQuery $query */
        $query = Bar::find();

        return $query
            ->siteId($siteId)
            ->status(Bar::STATUS_LIVE)
            ->orderBy(['blaster_bars.priority' => SORT_DESC, 'elements.id' => SORT_ASC])
            ->all();
    }

    public function count(): int
    {
        return (int)Bar::find()->status(null)->siteId('*')->unique()->count();
    }

    /**
     * Whether a handle is spoken for.
     *
     * Asked of an element query rather than of the table, because a soft-deleted bar keeps its
     * row: a table-level check would reserve handles for bars nobody can see, and the author is
     * told "already in use" by something that no longer exists anywhere in the control panel.
     */
    public function handleIsTaken(string $handle, ?int $exceptId = null): bool
    {
        $query = Bar::find()
            ->handle($handle)
            ->siteId('*')
            ->unique()
            ->status(null);

        if ($exceptId !== null) {
            $query->id("not $exceptId");
        }

        return $query->exists();
    }

    /** `spring-sale`, then `spring-sale-2`, and so on until one is free. */
    public function uniqueHandle(string $base, ?int $exceptId = null): string
    {
        $base = StringHelper::toKebabCase($base) ?: 'bar';

        if (!$this->handleIsTaken($base, $exceptId)) {
            return $base;
        }

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            if (!$this->handleIsTaken("$base-$suffix", $exceptId)) {
                return "$base-$suffix";
            }
        }

        return $base . '-' . StringHelper::randomString(6);
    }

    /**
     * Saves a bar, bumping its version.
     *
     * The bump is the point: the runtime keys a visitor's dismissal on the version, so any edit
     * brings the bar back for people who had closed the previous one. Saving without bumping
     * would leave a new announcement invisible to exactly the audience most likely to have seen
     * the old one.
     */
    public function saveBar(Bar $bar, bool $runValidation = true): bool
    {
        if ($bar->handle === '' && $bar->title) {
            $bar->handle = $this->uniqueHandle($bar->title, $bar->id);
        }

        $bar->version++;

        if (!Craft::$app->getElements()->saveElement($bar, $runValidation)) {
            $bar->version--;

            return false;
        }

        unset($this->byId[$bar->id]);

        return true;
    }

    public function deleteBar(Bar $bar): bool
    {
        unset($this->byId[$bar->id]);

        return Craft::$app->getElements()->deleteElement($bar);
    }

    /**
     * Copies a bar, disabled, so that duplicating something that is currently on the site does
     * not put a second copy of it on the site.
     */
    public function duplicateBar(Bar $bar): ?Bar
    {
        try {
            /** @var Bar $copy */
            $copy = Craft::$app->getElements()->duplicateElement($bar, [
                'title' => Craft::t('blaster', '{title} (copy)', ['title' => $bar->getUiLabel()]),
                'handle' => $this->uniqueHandle($bar->handle),
                'enabled' => false,
                'version' => 1,
            ]);
        } catch (Throwable $e) {
            Craft::error('Could not duplicate bar ' . $bar->id . ': ' . $e->getMessage(), 'blaster');

            return null;
        }

        unset($this->byId[$copy->id]);

        return $copy;
    }
}
