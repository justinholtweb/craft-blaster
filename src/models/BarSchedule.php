<?php

namespace justinholtweb\blaster\models;

use Craft;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeZone;

/**
 * When a bar is allowed to run.
 *
 * Dates are held as ISO 8601 strings in UTC rather than as `DateTime` properties. Craft's model
 * attribute casting has changed shape more than once across 5.x point releases, and a plugin that
 * stores its own JSON gains nothing from riding on it — a string round-trips through
 * `json_encode` unchanged, whatever the framework is doing this month.
 *
 * The daily window and days of week are evaluated in the **site's** time zone, which is the one
 * the author was thinking in when they typed "9am".
 */
class BarSchedule extends ConfigModel
{
    public bool $enabled = false;

    /** ISO 8601, UTC. Null means "no start date" rather than "starts now". */
    public ?string $start = null;

    /** ISO 8601, UTC. Null means "runs until switched off". */
    public ?string $end = null;

    /** ISO-8601 weekday numbers, 1 (Monday) to 7 (Sunday). Empty means every day. */
    public array $daysOfWeek = [];

    /** `HH:MM` in the site's time zone. Both ends must be set for the window to apply. */
    public ?string $dailyStart = null;

    public ?string $dailyEnd = null;


    /**
     * Craft's date and time fields post arrays (`['date' => …, 'time' => …, 'timezone' => …]`),
     * which the generic caster will not put into a `?string` property — it refuses anything
     * non-scalar rather than guessing. So they are flattened to the stored shape first.
     */
    public function applyConfig(array $config): void
    {
        foreach (['start', 'end'] as $key) {
            if (array_key_exists($key, $config) && !is_string($config[$key])) {
                $config[$key] = self::normalizeDate($config[$key]);
            }
        }

        foreach (['dailyStart', 'dailyEnd'] as $key) {
            if (array_key_exists($key, $config) && !is_string($config[$key])) {
                $config[$key] = self::normalizeTime(
                    is_array($config[$key]) ? ($config[$key]['time'] ?? '') : $config[$key],
                );
            }
        }

        parent::applyConfig($config);
    }

    public function normalize(): void
    {
        $this->start = self::normalizeDate($this->start);
        $this->end = self::normalizeDate($this->end);
        $this->dailyStart = self::normalizeTime($this->dailyStart);
        $this->dailyEnd = self::normalizeTime($this->dailyEnd);

        $this->daysOfWeek = array_values(array_unique(array_filter(
            array_map('intval', $this->daysOfWeek),
            fn(int $day) => $day >= 1 && $day <= 7,
        )));

        sort($this->daysOfWeek);
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value);

        return $date ? $date->setTimezone(new DateTimeZone('UTC'))->format(DateTime::ATOM) : null;
    }

    private static function normalizeTime(mixed $value): ?string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        // Accepts what Craft's time input posts (`14:30`, `2:30 PM`, `14:30:00`) and stores one shape.
        $time = DateTimeHelper::toDateTime(['date' => '1970-01-01', 'time' => $value], true);

        return $time ? $time->format('H:i') : null;
    }

    protected function defineRules(): array
    {
        return [
            [['enabled'], 'boolean'],
            [['start', 'end'], 'validateOrder', 'skipOnEmpty' => false],
            [['dailyStart', 'dailyEnd'], 'validateDailyWindow', 'skipOnEmpty' => false],
        ];
    }

    public function validateOrder(): void
    {
        if ($this->start && $this->end && $this->start >= $this->end) {
            $this->addError('end', Craft::t('blaster', 'The end date must come after the start date.'));
        }
    }

    /**
     * A window with only one end is ambiguous — "from 9am" could mean until midnight or until the
     * end date — so both are required together. A window whose end is *before* its start is not
     * rejected: an overnight bar (22:00–02:00) is a real thing, and
     * {@see \justinholtweb\blaster\services\Matcher} reads it as wrapping past midnight.
     */
    public function validateDailyWindow(): void
    {
        if (($this->dailyStart === null) !== ($this->dailyEnd === null)) {
            $this->addError('dailyEnd', Craft::t('blaster', 'Set both a daily start and end time, or neither.'));
        }
    }

    public function getStartDate(): ?DateTime
    {
        return $this->start ? DateTimeHelper::toDateTime($this->start) ?: null : null;
    }

    public function getEndDate(): ?DateTime
    {
        return $this->end ? DateTimeHelper::toDateTime($this->end) ?: null : null;
    }

    /** True when nothing here constrains anything, so the matcher can skip the work entirely. */
    public function isOpen(): bool
    {
        return !$this->enabled
            || (!$this->start && !$this->end && !$this->daysOfWeek && !$this->dailyStart);
    }

    /** Whether the bar has a start date it has not yet reached. */
    public function isPending(?DateTime $now = null): bool
    {
        if (!$this->enabled || !$this->start) {
            return false;
        }

        return ($now ?? new DateTime('now', new DateTimeZone('UTC'))) < $this->getStartDate();
    }

    public function isExpired(?DateTime $now = null): bool
    {
        if (!$this->enabled || !$this->end) {
            return false;
        }

        return ($now ?? new DateTime('now', new DateTimeZone('UTC'))) > $this->getEndDate();
    }

    /** The time zone the daily window and weekdays are read in. */
    public static function timeZone(): DateTimeZone
    {
        return new DateTimeZone(Craft::$app->getTimeZone());
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'enabled' => $this->enabled,
            'start' => $this->start,
            'end' => $this->end,
            'daysOfWeek' => $this->daysOfWeek,
            'dailyStart' => $this->dailyStart,
            'dailyEnd' => $this->dailyEnd,
        ];
    }
}
