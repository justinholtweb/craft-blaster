<?php

namespace justinholtweb\blaster\models;


/**
 * When a bar appears, how it appears, and what makes it go away.
 *
 * Named "display" rather than the more obvious "behavior" because `yii\base\Component` already
 * declares `getBehavior(string $name)`, and an accessor that differs only in arity is a fatal
 * error the moment the class is autoloaded — not at the call site, and not as a warning.
 *
 * Every field here is read by both PHP (validation, the CP form) and the runtime (which acts on
 * it), so the JSON shape produced by {@see toArray()} is a contract, not an implementation
 * detail. Renaming a key is a migration.
 */
class BarDisplay extends ConfigModel
{
    public const TRIGGER_IMMEDIATE = 'immediate';
    public const TRIGGER_DELAY = 'delay';
    public const TRIGGER_SCROLL = 'scroll';
    public const TRIGGER_EXIT = 'exit';

    public const ANIMATION_NONE = 'none';
    public const ANIMATION_SLIDE = 'slide';
    public const ANIMATION_FADE = 'fade';

    public string $trigger = self::TRIGGER_IMMEDIATE;

    /** Seconds to wait before showing, when the trigger is {@see TRIGGER_DELAY}. */
    public int $delaySeconds = 3;

    /** How far down the page the visitor must scroll, as a percentage, for {@see TRIGGER_SCROLL}. */
    public int $scrollPercent = 25;

    public string $animation = self::ANIMATION_SLIDE;

    /** Animation length in milliseconds. */
    public int $animationDuration = 350;

    /** Seconds on screen before the bar closes itself. 0 means it stays until dismissed. */
    public int $autoCloseSeconds = 0;

    /** Whether the bar carries a close button at all. */
    public bool $dismissible = true;

    /**
     * How long a dismissal lasts, in days. 0 means "this browsing session only" — the runtime
     * uses sessionStorage for that case, so nothing outlives the tab.
     */
    public int $dismissDays = 7;

    /** After closing, leave a small tab the visitor can click to bring the bar back. */
    public bool $showReopenTab = false;

    /** Stop showing after this many impressions to one visitor. 0 means no cap. */
    public int $maxViews = 0;

    /** Stay pinned while the page scrolls, rather than scrolling away with the document. */
    public bool $sticky = true;

    /** Push the page down instead of sitting on top of it. */
    public bool $pushPage = true;


    protected function defineRules(): array
    {
        return [
            [['trigger'], 'in', 'range' => [
                self::TRIGGER_IMMEDIATE,
                self::TRIGGER_DELAY,
                self::TRIGGER_SCROLL,
                self::TRIGGER_EXIT,
            ]],
            [['animation'], 'in', 'range' => [
                self::ANIMATION_NONE,
                self::ANIMATION_SLIDE,
                self::ANIMATION_FADE,
            ]],
            [['delaySeconds', 'autoCloseSeconds', 'dismissDays', 'maxViews'], 'integer', 'min' => 0],
            [['scrollPercent'], 'integer', 'min' => 1, 'max' => 100],
            [['animationDuration'], 'integer', 'min' => 0, 'max' => 5000],
            [['dismissible', 'showReopenTab', 'sticky', 'pushPage'], 'boolean'],
            [['showReopenTab'], 'validateReopenNeedsClose'],
        ];
    }

    /**
     * A reopen tab with no close button is a control that can never be reached, so it is a
     * configuration mistake worth naming rather than quietly ignoring.
     */
    public function validateReopenNeedsClose(): void
    {
        if ($this->showReopenTab && !$this->dismissible) {
            $this->addError('showReopenTab', \Craft::t('blaster', 'A reopen tab needs a close button to reopen from.'));
        }
    }

    /** The subset the runtime needs. Everything else is acted on server-side or in CSS. */
    public function forRuntime(): array
    {
        return [
            'trigger' => $this->trigger,
            'delay' => $this->delaySeconds,
            'scroll' => $this->scrollPercent,
            'animation' => $this->animation,
            'duration' => $this->animationDuration,
            'autoClose' => $this->autoCloseSeconds,
            'dismissible' => $this->dismissible,
            'dismissDays' => $this->dismissDays,
            'reopen' => $this->showReopenTab,
            'maxViews' => $this->maxViews,
            'sticky' => $this->sticky,
            'push' => $this->pushPage,
        ];
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'trigger' => $this->trigger,
            'delaySeconds' => $this->delaySeconds,
            'scrollPercent' => $this->scrollPercent,
            'animation' => $this->animation,
            'animationDuration' => $this->animationDuration,
            'autoCloseSeconds' => $this->autoCloseSeconds,
            'dismissible' => $this->dismissible,
            'dismissDays' => $this->dismissDays,
            'showReopenTab' => $this->showReopenTab,
            'maxViews' => $this->maxViews,
            'sticky' => $this->sticky,
            'pushPage' => $this->pushPage,
        ];
    }
}
