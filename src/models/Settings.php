<?php

namespace justinholtweb\blaster\models;

use craft\base\Model;

/**
 * Plugin-wide settings.
 *
 * Nothing here is `required` — a fresh install has to be able to save its settings before it has
 * been configured, and a `required` rule fails `savePluginSettings()` wholesale, taking every
 * unrelated setting with it.
 */
class Settings extends Model
{
    /**
     * Splice matching bars into front-end HTML responses automatically.
     *
     * Turn this off to place bars yourself with `{{ craft.blaster.render() }}`. Rendering
     * manually also stands injection down for that request on its own, so the switch is only
     * needed for sites that want the tag to be the *only* way bars can appear.
     */
    public bool $autoInject = true;

    /**
     * URI patterns that never receive an injected bar, whatever their targeting says.
     *
     * Glob patterns, or `re:` followed by a regular expression. Useful for HTML endpoints that
     * are not really pages — embeds, printable views, an HTMX fragment route.
     */
    public array $excludedUris = [];

    /** Stacking order for the bar container. High enough to clear most sticky headers. */
    public int $zIndex = 99999;

    /** Record impressions, clicks and dismissals. Counts only — no visitor is ever identified. */
    public bool $trackStats = true;

    /**
     * Skip counting for visitors sending `DNT: 1`.
     *
     * Off by default: Blaster's counters hold no identifier, no address and no session, so they
     * do not track anyone in the sense the header is asking about. Sites with a stricter policy
     * can switch it on and lose the corresponding share of their numbers.
     */
    public bool $respectDoNotTrack = false;

    /** Days of daily stats to keep. 0 keeps everything. */
    public int $statsRetentionDays = 730;

    /** Name of a `config/htmlpurifier/*.json` file to clean bar messages with. */
    public string $purifierConfig = '';

    protected function defineRules(): array
    {
        return [
            [['autoInject', 'trackStats', 'respectDoNotTrack'], 'boolean'],
            [['zIndex'], 'integer', 'min' => 0],
            [['statsRetentionDays'], 'integer', 'min' => 0],
            [['purifierConfig'], 'string'],
            [['excludedUris'], 'safe'],
        ];
    }

    /**
     * Settings arrive from the control panel's editable table as rows, and from `config/blaster.php`
     * as a plain list of strings. Both have to end up as the same list.
     */
    public function beforeValidate(): bool
    {
        $this->normalizeExcludedUris();

        return parent::beforeValidate();
    }

    /** The CP posts this as a table; the blank trailing row is not an exclusion of everything. */
    public function normalizeExcludedUris(): void
    {
        $this->excludedUris = array_values(array_filter(array_map(
            fn($row) => trim((string)(is_array($row) ? ($row['pattern'] ?? '') : $row)),
            $this->excludedUris,
        ), fn(string $pattern) => $pattern !== ''));
    }
}
