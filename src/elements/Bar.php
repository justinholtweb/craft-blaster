<?php

namespace justinholtweb\blaster\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\enums\Color;
use DateTime;
use DateTimeZone;
use justinholtweb\blaster\elements\db\BarQuery;
use justinholtweb\blaster\models\BarDisplay;
use justinholtweb\blaster\models\BarContent;
use justinholtweb\blaster\models\BarSchedule;
use justinholtweb\blaster\models\BarTargeting;
use justinholtweb\blaster\models\BarTheme;
use justinholtweb\blaster\Plugin;
use justinholtweb\blaster\records\BarContentRecord;
use justinholtweb\blaster\records\BarRecord;

/**
 * A notification bar.
 *
 * Localized: a bar exists on every site, and its wording is translated per site while its
 * colours, targeting and schedule are shared. The alternative — one bar per language — means two
 * things to keep in step and one of them always drifts.
 *
 * The configuration lives in four JSON columns backed by typed models rather than in a wide
 * table, because none of it is ever queried; it is read whole, by one bar at a time, on the way
 * to being rendered. The two exceptions are `startDate` and `endDate`, which are denormalised out
 * of {@see BarSchedule} so the index can answer "what is live right now" in SQL.
 */
class Bar extends Element
{
    public const STATUS_LIVE = 'live';
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXPIRED = 'expired';

    public const POSITION_TOP = 'top';
    public const POSITION_BOTTOM = 'bottom';

    public string $handle = '';

    public string $position = self::POSITION_TOP;

    /** Higher wins when several bars match the same request and position. */
    public int $priority = 0;

    /**
     * Bumped on every save.
     *
     * The runtime stores a dismissal against this number, so editing a bar makes it reappear for
     * people who had closed the previous version. A stale dismissal quietly suppressing a new
     * announcement is the failure mode nobody notices until the announcement matters.
     */
    public int $version = 1;

    private ?BarDisplay $_display = null;
    private ?BarTargeting $_targeting = null;
    private ?BarSchedule $_schedule = null;
    private ?BarTheme $_theme = null;
    private ?BarContent $_content = null;

    /** Raw JSON straight off the query, decoded lazily so an index listing 200 bars does not. */
    public ?string $displayJson = null;
    public ?string $targetingJson = null;
    public ?string $scheduleJson = null;
    public ?string $themeJson = null;

    // ------------------------------------------------------------------ identity

    public static function displayName(): string
    {
        return Craft::t('blaster', 'Bar');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('blaster', 'Bars');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('blaster', 'bar');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('blaster', 'bars');
    }

    public static function refHandle(): ?string
    {
        return 'blaster';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function find(): ElementQueryInterface
    {
        return new BarQuery(static::class);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_LIVE => ['label' => Craft::t('blaster', 'Live'), 'color' => Color::Green],
            self::STATUS_PENDING => ['label' => Craft::t('blaster', 'Pending'), 'color' => Color::Orange],
            self::STATUS_EXPIRED => ['label' => Craft::t('blaster', 'Expired'), 'color' => Color::Red],
            self::STATUS_DISABLED => ['label' => Craft::t('blaster', 'Disabled'), 'color' => Color::Gray],
        ];
    }

    /**
     * Enabled is not the same as showing.
     *
     * A bar can be switched on and still be waiting for its start date or past its end date, and
     * an author looking at the index needs to be able to tell those apart at a glance — "enabled
     * but invisible" is the state that generates support tickets.
     */
    public function getStatus(): ?string
    {
        $status = parent::getStatus();

        if ($status !== self::STATUS_ENABLED) {
            return $status;
        }

        $schedule = $this->getSchedule();
        $now = new DateTime('now', new DateTimeZone('UTC'));

        if ($schedule->isPending($now)) {
            return self::STATUS_PENDING;
        }

        if ($schedule->isExpired($now)) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_LIVE;
    }

    /** Bars are site-wide furniture, so every site is a candidate. */
    public function getSupportedSites(): array
    {
        return array_map(
            fn(int $siteId) => ['siteId' => $siteId, 'enabledByDefault' => true],
            Craft::$app->getSites()->getAllSiteIds(),
        );
    }

    // ------------------------------------------------------------------ configuration

    /**
     * The bar's display rules.
     *
     * Not `getBehavior()`: `yii\base\Component` declares that name with a required argument, and
     * overriding it with a different signature is a fatal error at autoload time.
     */
    public function getDisplay(): BarDisplay
    {
        return $this->_display ??= BarDisplay::fromArray($this->decode($this->displayJson));
    }

    public function setDisplay(BarDisplay|array|null $display): void
    {
        $this->_display = $display instanceof BarDisplay ? $display : BarDisplay::fromArray($display);
    }

    public function getTargeting(): BarTargeting
    {
        return $this->_targeting ??= BarTargeting::fromArray($this->decode($this->targetingJson));
    }

    public function setTargeting(BarTargeting|array|null $targeting): void
    {
        $this->_targeting = $targeting instanceof BarTargeting ? $targeting : BarTargeting::fromArray($targeting);
    }

    public function getSchedule(): BarSchedule
    {
        return $this->_schedule ??= BarSchedule::fromArray($this->decode($this->scheduleJson));
    }

    public function setSchedule(BarSchedule|array|null $schedule): void
    {
        $this->_schedule = $schedule instanceof BarSchedule ? $schedule : BarSchedule::fromArray($schedule);
    }

    public function getTheme(): BarTheme
    {
        return $this->_theme ??= BarTheme::fromArray($this->decode($this->themeJson));
    }

    public function setTheme(BarTheme|array|null $theme): void
    {
        $this->_theme = $theme instanceof BarTheme ? $theme : BarTheme::fromArray($theme);
    }

    /**
     * The wording for this element's site.
     *
     * Loaded on demand rather than joined into the index query: the index shows titles and
     * statuses, and joining a text column onto every row to display none of it is a cost paid on
     * the screen that can least afford it.
     */
    public function getContent(): BarContent
    {
        if ($this->_content !== null) {
            return $this->_content;
        }

        if (!$this->id || !$this->siteId) {
            return $this->_content = new BarContent(['siteId' => $this->siteId]);
        }

        $row = BarContentRecord::findOne(['id' => $this->id, 'siteId' => $this->siteId]);

        return $this->_content = BarContent::fromArray([
            'siteId' => $this->siteId,
            'message' => (string)($row->message ?? ''),
            'buttonEnabled' => (bool)($row->buttonEnabled ?? false),
            'buttonLabel' => (string)($row->buttonLabel ?? ''),
            'buttonUrl' => (string)($row->buttonUrl ?? ''),
            'buttonNewWindow' => (bool)($row->buttonNewWindow ?? false),
        ]);
    }

    public function setContent(BarContent|array|null $content): void
    {
        $this->_content = $content instanceof BarContent ? $content : BarContent::fromArray($content);
        $this->_content->siteId = $this->siteId;
    }

    private function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    // ------------------------------------------------------------------ validation

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['handle'], 'required'];
        $rules[] = [['handle'], 'string', 'max' => 255];
        $rules[] = [['handle'], 'match',
            'pattern' => '/^[a-z][a-z0-9\-]*$/',
            'message' => Craft::t('blaster', 'Handles start with a letter and contain only lowercase letters, numbers and hyphens.'),
        ];
        $rules[] = [['handle'], 'validateHandleIsFree'];
        $rules[] = [['position'], 'in', 'range' => [self::POSITION_TOP, self::POSITION_BOTTOM]];
        $rules[] = [['priority'], 'integer'];
        $rules[] = [['display', 'targeting', 'schedule', 'theme', 'content'], 'validateSubModel'];

        return $rules;
    }

    /**
     * Handle uniqueness, asked of an element query rather than of the table.
     *
     * A soft-deleted bar keeps its row, so a unique index would hold its handle forever against a
     * bar nobody can see or restore-and-rename. An element query excludes trashed rows, which is
     * the question actually being asked.
     */
    public function validateHandleIsFree(): void
    {
        if ($this->handle === '') {
            return;
        }

        if (Plugin::getInstance()->bars->handleIsTaken($this->handle, $this->id)) {
            $this->addError('handle', Craft::t('blaster', '“{handle}” is already in use.', ['handle' => $this->handle]));
        }
    }

    /** Rolls each config model's errors up onto the element, so the CP can show them in place. */
    public function validateSubModel(string $attribute): void
    {
        $getter = 'get' . ucfirst($attribute);
        $model = $this->$getter();

        if ($model->validate()) {
            return;
        }

        foreach ($model->getErrors() as $field => $errors) {
            foreach ($errors as $error) {
                $this->addError("$attribute.$field", $error);
            }
        }
    }

    // ------------------------------------------------------------------ persistence

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $this->saveBarRecord($isNew);
        }

        $this->saveContentRecord();

        parent::afterSave($isNew);
    }

    private function saveBarRecord(bool $isNew): void
    {
        $record = $isNew ? new BarRecord() : (BarRecord::findOne($this->id) ?? new BarRecord());
        $record->id = $this->id;
        $record->handle = $this->handle;
        $record->position = $this->position;
        $record->priority = $this->priority;
        $record->version = $this->version;
        $record->display = json_encode($this->getDisplay()->toArray());
        $record->targeting = json_encode($this->getTargeting()->toArray());
        $record->schedule = json_encode($this->getSchedule()->toArray());
        $record->theme = json_encode($this->getTheme()->toArray());

        $schedule = $this->getSchedule();
        $record->startDate = $schedule->enabled && $schedule->start ? Db::prepareDateForDb($schedule->getStartDate()) : null;
        $record->endDate = $schedule->enabled && $schedule->end ? Db::prepareDateForDb($schedule->getEndDate()) : null;

        $record->save(false);
    }

    /**
     * Writes this site's wording.
     *
     * A propagated save carries the *originating* site's content, so it must not overwrite a
     * translation that already exists — it may only seed a site that has none yet. Getting this
     * backwards turns every edit on the English bar into a silent overwrite of the Spanish one.
     */
    private function saveContentRecord(): void
    {
        $existing = BarContentRecord::findOne(['id' => $this->id, 'siteId' => $this->siteId]);

        if ($this->propagating && $existing !== null) {
            return;
        }

        $record = $existing ?? new BarContentRecord(['id' => $this->id, 'siteId' => $this->siteId]);
        $content = $this->getContent();

        $record->message = $content->message;
        $record->buttonEnabled = $content->buttonEnabled;
        $record->buttonLabel = $content->buttonLabel;
        $record->buttonUrl = $content->buttonUrl;
        $record->buttonNewWindow = $content->buttonNewWindow;

        $record->save(false);
    }

    /**
     * Parks the handle while the bar is in the trash.
     *
     * Without this the handle is unavailable to anything new, with no visible bar to explain why.
     */
    public function afterDelete(): void
    {
        if ($this->id && !$this->hardDelete) {
            Craft::$app->getDb()->createCommand()
                ->update(BarRecord::TABLE, ['handle' => $this->handle . '--trashed-' . $this->id], ['id' => $this->id])
                ->execute();
        }

        parent::afterDelete();
    }

    public function afterRestore(): void
    {
        $handle = preg_replace('/--trashed-\d+$/', '', $this->handle);

        if (Plugin::getInstance()->bars->handleIsTaken($handle, $this->id)) {
            $handle = Plugin::getInstance()->bars->uniqueHandle($handle, $this->id);
        }

        $this->handle = $handle;

        Craft::$app->getDb()->createCommand()
            ->update(BarRecord::TABLE, ['handle' => $handle], ['id' => $this->id])
            ->execute();

        parent::afterRestore();
    }

    // ------------------------------------------------------------------ control panel

    public function canView(mixed $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW);
    }

    public function canSave(mixed $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDelete(mixed $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE);
    }

    public function canDuplicate(mixed $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canCreateDrafts(mixed $user): bool
    {
        return false;
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("blaster/bars/$this->id");
    }

    protected static function defineSources(string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('blaster', 'All bars'),
                'defaultSort' => ['priority', 'desc'],
            ],
            ['heading' => Craft::t('blaster', 'Position')],
            [
                'key' => 'position:top',
                'label' => Craft::t('blaster', 'Top'),
                'criteria' => ['position' => self::POSITION_TOP],
                'defaultSort' => ['priority', 'desc'],
            ],
            [
                'key' => 'position:bottom',
                'label' => Craft::t('blaster', 'Bottom'),
                'criteria' => ['position' => self::POSITION_BOTTOM],
                'defaultSort' => ['priority', 'desc'],
            ],
        ];
    }

    protected static function defineActions(string $source = null): array
    {
        return [
            [
                'type' => Delete::class,
                'confirmationMessage' => Craft::t('blaster', 'Are you sure you want to delete the selected bars?'),
                'successMessage' => Craft::t('blaster', 'Bars deleted.'),
            ],
            [
                'type' => Restore::class,
                'successMessage' => Craft::t('blaster', 'Bars restored.'),
                'partialSuccessMessage' => Craft::t('blaster', 'Some bars restored.'),
                'failMessage' => Craft::t('blaster', 'Bars not restored.'),
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'handle' => ['label' => Craft::t('app', 'Handle')],
            'position' => ['label' => Craft::t('blaster', 'Position')],
            'priority' => ['label' => Craft::t('blaster', 'Priority')],
            'targetsLabel' => ['label' => Craft::t('blaster', 'Targeting')],
            'scheduleLabel' => ['label' => Craft::t('blaster', 'Schedule')],
            'views' => ['label' => Craft::t('blaster', 'Views')],
            'clicks' => ['label' => Craft::t('blaster', 'Clicks')],
            'ctr' => ['label' => Craft::t('blaster', 'CTR')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['handle', 'position', 'priority', 'scheduleLabel', 'views', 'clicks', 'ctr'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'handle' => Craft::t('app', 'Handle'),
            'priority' => Craft::t('blaster', 'Priority'),
            'position' => Craft::t('blaster', 'Position'),
            [
                'label' => Craft::t('blaster', 'Start date'),
                'orderBy' => 'blaster_bars.startDate',
                'attribute' => 'startDate',
            ],
            'dateUpdated' => Craft::t('app', 'Date Updated'),
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'handle'];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'handle' => Html::tag('code', Html::encode($this->handle)),
            'position' => Html::encode($this->position === self::POSITION_TOP
                ? Craft::t('blaster', 'Top')
                : Craft::t('blaster', 'Bottom')),
            'targetsLabel' => Html::encode($this->getTargetingSummary()),
            'scheduleLabel' => Html::encode($this->getScheduleSummary()),
            'views', 'clicks', 'ctr' => $this->statHtml($attribute),
            default => parent::attributeHtml($attribute),
        };
    }

    private function statHtml(string $attribute): string
    {
        $totals = Plugin::getInstance()->stats->totalsForBar($this->id);

        if ($attribute === 'ctr') {
            $views = (int)$totals['views'];

            return $views === 0
                ? '—'
                : Html::encode(number_format(round(($totals['clicks'] / $views) * 100, 1), 1) . '%');
        }

        return Html::encode(Craft::$app->getFormatter()->asDecimal((int)$totals[$attribute], 0));
    }

    /** A one-line answer to "where does this show?" for the index. */
    public function getTargetingSummary(): string
    {
        $targeting = $this->getTargeting();

        $where = match ($targeting->pages) {
            BarTargeting::PAGES_HOME => Craft::t('blaster', 'Homepage'),
            BarTargeting::PAGES_URIS => Craft::t('blaster', '{n} URI rules', ['n' => count($targeting->uriRules)]),
            BarTargeting::PAGES_ELEMENTS => Craft::t('blaster', '{n} sections/entries', [
                'n' => count($targeting->sectionUids) + count($targeting->entryIds),
            ]),
            default => Craft::t('blaster', 'All pages'),
        };

        $who = match ($targeting->auth) {
            BarTargeting::AUTH_GUESTS => Craft::t('blaster', 'signed-out'),
            BarTargeting::AUTH_MEMBERS => Craft::t('blaster', 'signed-in'),
            default => null,
        };

        return $who ? "$where · $who" : $where;
    }

    public function getScheduleSummary(): string
    {
        $schedule = $this->getSchedule();

        if (!$schedule->enabled) {
            return Craft::t('blaster', 'Always');
        }

        $formatter = Craft::$app->getFormatter();
        $start = $schedule->getStartDate();
        $end = $schedule->getEndDate();

        if ($start && $end) {
            return $formatter->asDate($start, 'short') . ' – ' . $formatter->asDate($end, 'short');
        }

        if ($start) {
            return Craft::t('blaster', 'From {date}', ['date' => $formatter->asDate($start, 'short')]);
        }

        if ($end) {
            return Craft::t('blaster', 'Until {date}', ['date' => $formatter->asDate($end, 'short')]);
        }

        return Craft::t('blaster', 'Recurring');
    }

    public function getUiLabel(): string
    {
        return $this->title ?: $this->handle ?: Craft::t('blaster', 'Untitled bar');
    }

    /** The DOM id the runtime and the generated CSS both address. */
    public function domId(): string
    {
        return 'blaster-bar-' . $this->id;
    }
}
