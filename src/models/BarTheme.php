<?php

namespace justinholtweb\blaster\models;

use Craft;

/**
 * How a bar looks, and the CSS that makes it look that way.
 *
 * The CSS is generated here rather than in the renderer so that it is pure and can be tested
 * without an application, and so that the control panel's live preview and the front end cannot
 * drift apart — both call {@see toCss()}.
 *
 * **Every value interpolated into CSS is sanitised on the way out**, not on the way in. Stored
 * data can arrive from a project-config sync, a restored database or an older schema, so the
 * boundary that matters is the one next to the output. Colours that do not match a strict
 * pattern fall back to the default rather than reaching the stylesheet.
 */
class BarTheme extends ConfigModel
{
    public const ALIGN_LEFT = 'left';
    public const ALIGN_CENTER = 'center';
    public const ALIGN_RIGHT = 'right';

    public const WIDTH_FULL = 'full';
    public const WIDTH_CONTAINED = 'contained';

    public string $background = '#1B8EF2';
    public string $text = '#FFFFFF';
    public string $link = '#FFFFFF';
    public string $closeColor = '#FFFFFF';

    public string $buttonBackground = '#0E2A47';
    public string $buttonText = '#FFFFFF';
    public int $buttonRadius = 4;

    /** Minimum bar height in pixels. 0 lets the content decide. */
    public int $minHeight = 0;

    public int $fontSize = 16;

    public string $align = self::ALIGN_CENTER;

    public string $width = self::WIDTH_FULL;

    /** Inner content width when {@see $width} is `contained`. */
    public int $maxWidth = 1200;

    public int $borderWidth = 0;

    public string $borderColor = '#0E2A47';

    public bool $shadow = false;

    /**
     * Extra CSS, emitted verbatim inside the bar's `<style>` element.
     *
     * `{selector}` is replaced with the bar's own selector, which is the only practical way to
     * scope hand-written CSS without shipping a parser. Writing this requires the
     * `blaster:manageBars` permission.
     */
    public string $customCss = '';


    /** Every attribute that holds a colour, and so needs normalising on the way in. */
    public const COLOUR_ATTRIBUTES = [
        'background', 'text', 'link', 'closeColor', 'buttonBackground', 'buttonText', 'borderColor',
    ];

    /**
     * Puts the `#` back on colours.
     *
     * Craft's colour field posts its value **without** a leading hash — the input displays and
     * submits `1B8EF2`, not `#1B8EF2`. Without this, every colour saved through the control panel
     * fails the hex pattern and quietly renders as the default, and because the shipped default
     * *is* `#1B8EF2` the bug is invisible until somebody picks a different colour.
     *
     * A cleared field drops out entirely rather than being stored as an empty string, so the
     * default stands instead of validation refusing a blank colour the author never filled in.
     */
    public function applyConfig(array $config): void
    {
        foreach (self::COLOUR_ATTRIBUTES as $key) {
            if (!array_key_exists($key, $config) || !is_string($config[$key])) {
                continue;
            }

            $value = trim($config[$key]);

            if ($value === '') {
                unset($config[$key]);
                continue;
            }

            $config[$key] = str_starts_with($value, '#') ? $value : '#' . $value;
        }

        parent::applyConfig($config);
    }

    protected function defineRules(): array
    {
        return [
            [self::COLOUR_ATTRIBUTES, 'match',
                'pattern' => '/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i',
                'message' => Craft::t('blaster', '{attribute} must be a hex colour, such as #1B8EF2.'),
            ],
            [['align'], 'in', 'range' => [self::ALIGN_LEFT, self::ALIGN_CENTER, self::ALIGN_RIGHT]],
            [['width'], 'in', 'range' => [self::WIDTH_FULL, self::WIDTH_CONTAINED]],
            [['minHeight', 'borderWidth', 'buttonRadius'], 'integer', 'min' => 0, 'max' => 500],
            [['fontSize'], 'integer', 'min' => 8, 'max' => 72],
            [['maxWidth'], 'integer', 'min' => 200, 'max' => 4000],
            [['shadow'], 'boolean'],
            [['customCss'], 'match',
                'pattern' => '/</',
                'not' => true,
                'message' => Craft::t('blaster', 'Custom CSS can’t contain “<”. Use `\\3C` if you need one inside a string.'),
            ],
        ];
    }

    /** A colour that survived {@see defineRules()}, or the default if the stored value did not. */
    private function colour(string $attribute): string
    {
        $value = (string)$this->$attribute;

        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)) {
            return $value;
        }

        return (new self())->$attribute;
    }

    private function px(int $value, int $min, int $max, int $fallback): int
    {
        return $value >= $min && $value <= $max ? $value : $fallback;
    }

    /**
     * The stylesheet for one bar.
     *
     * @param string $selector The bar's own selector, e.g. `#blaster-bar-7`.
     */
    public function toCss(string $selector): string
    {
        $defaults = new self();
        $align = in_array($this->align, [self::ALIGN_LEFT, self::ALIGN_CENTER, self::ALIGN_RIGHT], true)
            ? $this->align
            : self::ALIGN_CENTER;

        $justify = match ($align) {
            self::ALIGN_LEFT => 'flex-start',
            self::ALIGN_RIGHT => 'flex-end',
            default => 'center',
        };

        $fontSize = $this->px($this->fontSize, 8, 72, $defaults->fontSize);
        $minHeight = $this->px($this->minHeight, 0, 500, 0);
        $borderWidth = $this->px($this->borderWidth, 0, 500, 0);
        $buttonRadius = $this->px($this->buttonRadius, 0, 500, $defaults->buttonRadius);
        $maxWidth = $this->px($this->maxWidth, 200, 4000, $defaults->maxWidth);

        $rules = [];

        $rules[] = "$selector{"
            . 'background:' . $this->colour('background') . ';'
            . 'color:' . $this->colour('text') . ';'
            . "font-size:{$fontSize}px;"
            . ($minHeight > 0 ? "min-height:{$minHeight}px;" : '')
            . ($borderWidth > 0 ? "--blaster-border:{$borderWidth}px solid " . $this->colour('borderColor') . ';' : '')
            . ($this->shadow ? '--blaster-shadow:0 2px 12px rgba(0,0,0,.18);' : '')
            . '}';

        $rules[] = "$selector .blaster-bar__inner{justify-content:$justify;"
            . ($this->width === self::WIDTH_CONTAINED ? "max-width:{$maxWidth}px;margin:0 auto;" : '')
            . '}';

        $rules[] = "$selector .blaster-bar__message a{color:" . $this->colour('link') . ';}';

        $rules[] = "$selector .blaster-bar__button{"
            . 'background:' . $this->colour('buttonBackground') . ';'
            . 'color:' . $this->colour('buttonText') . ';'
            . "border-radius:{$buttonRadius}px;"
            . '}';

        $rules[] = "$selector .blaster-bar__close,$selector .blaster-bar__reopen{color:" . $this->colour('closeColor') . ';}';

        $custom = $this->safeCustomCss($selector);

        if ($custom !== '') {
            $rules[] = $custom;
        }

        return implode('', $rules);
    }

    /**
     * Custom CSS with `{selector}` resolved, and every `<` removed.
     *
     * The CSS is written into a `<style>` element, and `</style` is the one way hand-written CSS
     * can become markup. Removing only that sequence is not enough: done once, `</st</styleyle>`
     * rebuilds it from the pieces either side. CSS never needs a literal `<` (a string can use
     * `\3C`), so none survives — which leaves nothing to build a tag from, however it is nested.
     * {@see defineRules()} refuses one on save, so an author is told rather than silently edited;
     * this is the backstop for anything stored before that rule existed.
     */
    public function safeCustomCss(string $selector): string
    {
        $css = trim($this->customCss);

        if ($css === '') {
            return '';
        }

        $css = str_replace('{selector}', $selector, $css);

        return str_replace('<', '', $css);
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'background' => $this->background,
            'text' => $this->text,
            'link' => $this->link,
            'closeColor' => $this->closeColor,
            'buttonBackground' => $this->buttonBackground,
            'buttonText' => $this->buttonText,
            'buttonRadius' => $this->buttonRadius,
            'minHeight' => $this->minHeight,
            'fontSize' => $this->fontSize,
            'align' => $this->align,
            'width' => $this->width,
            'maxWidth' => $this->maxWidth,
            'borderWidth' => $this->borderWidth,
            'borderColor' => $this->borderColor,
            'shadow' => $this->shadow,
            'customCss' => $this->customCss,
        ];
    }
}
