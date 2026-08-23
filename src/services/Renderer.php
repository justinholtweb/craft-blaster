<?php

namespace justinholtweb\blaster\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use justinholtweb\blaster\elements\Bar;
use justinholtweb\blaster\Plugin;
use Twig\Markup;

/**
 * Turns bars into the markup, CSS and configuration the browser receives.
 *
 * This is the single source of the front-end's HTML. The control panel's live preview goes
 * through here too, over Ajax, rather than rebuilding the same markup in JavaScript — a preview
 * that is drawn by different code from the thing it previews is a preview of nothing.
 */
class Renderer extends Component
{
    /**
     * Whether the shared chrome (base stylesheet and runtime) has already gone out this request.
     *
     * Rendering is idempotent per request even if a template calls the Twig tag twice.
     */
    private bool $chromeRendered = false;

    private ?string $baseCss = null;
    private ?string $runtimeJs = null;

    /**
     * The whole payload: container, per-bar markup, styles and runtime.
     *
     * @param Bar[] $bars Already filtered by {@see Matcher} and ordered by priority.
     */
    public function renderAll(array $bars, ?int $siteId = null): string
    {
        if (!$bars) {
            return '';
        }

        $settings = Plugin::getInstance()->getSettings();
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        $css = ':root{--blaster-z:' . (int)$settings->zIndex . '}' . $this->baseCss();
        $markup = [];
        $reopens = [];

        foreach ($bars as $bar) {
            $css .= $bar->getTheme()->toCss('#' . $bar->domId());
            $markup[] = $this->renderBar($bar);

            if ($bar->getDisplay()->showReopenTab) {
                $reopens[] = $this->renderReopenTab($bar);
            }
        }

        $attributes = Html::renderTagAttributes([
            'class' => 'blaster',
            'data-blaster' => true,
            'data-blaster-site' => (string)$siteId,
            'data-blaster-track' => $settings->trackStats ? '1' : '0',
            'data-blaster-endpoint' => $settings->trackStats ? UrlHelper::actionUrl('blaster/track/hit') : '',
        ]);

        $this->chromeRendered = true;

        return '<div' . $attributes . '>'
            . '<style>' . $css . '</style>'
            . implode('', $markup)
            . implode('', $reopens)
            . '<script>' . $this->runtimeJs() . '</script>'
            . '</div>';
    }

    public function hasRendered(): bool
    {
        return $this->chromeRendered;
    }

    /** One bar, hidden, carrying its client-side configuration. */
    public function renderBar(Bar $bar): string
    {
        $display = $bar->getDisplay();
        $content = $bar->getContent();

        $classes = [
            'blaster-bar',
            'blaster-bar--' . ($bar->position === Bar::POSITION_BOTTOM ? 'bottom' : 'top'),
        ];

        if (!$display->dismissible) {
            $classes[] = 'blaster-bar--no-close';
        }

        $inner = '<div class="blaster-bar__message">' . $content->message . '</div>';

        if ($content->buttonEnabled) {
            $inner .= Html::tag('a', Html::encode($content->buttonLabel), array_filter([
                'class' => 'blaster-bar__button',
                'href' => $this->safeUrl($content->buttonUrl),
                'data-blaster-cta' => true,
                'target' => $content->buttonNewWindow ? '_blank' : null,
                'rel' => $content->buttonNewWindow ? 'noopener' : null,
            ]));
        }

        $body = '<div class="blaster-bar__inner">' . $inner . '</div>';

        if ($display->dismissible) {
            $body .= Html::tag('button', '&times;', [
                'type' => 'button',
                'class' => 'blaster-bar__close',
                'data-blaster-close' => true,
                'aria-label' => Craft::t('blaster', 'Close'),
            ]);
        }

        return Html::tag('div', $body, [
            'id' => $bar->domId(),
            'class' => $classes,
            'role' => 'region',
            'aria-label' => Craft::t('blaster', 'Notification'),
            'data-blaster-bar' => true,

            // JSON in an attribute comes back HTML-escaped; `JSON.parse(el.getAttribute(…))`
            // reads the decoded value, so nothing has to unescape it by hand.
            'data-blaster-config' => json_encode($this->runtimeConfig($bar)),
        ]);
    }

    private function renderReopenTab(Bar $bar): string
    {
        return Html::tag('button', Html::encode(Craft::t('blaster', 'Show notice')), [
            'type' => 'button',
            'class' => ['blaster-reopen', 'blaster-reopen--' . ($bar->position === Bar::POSITION_BOTTOM ? 'bottom' : 'top')],
            'data-blaster-reopen' => (string)$bar->id,
            'style' => 'background:' . $bar->getTheme()->background . ';color:' . $bar->getTheme()->text,
        ]);
    }

    /**
     * What the runtime needs to decide and behave.
     *
     * Nothing here is secret — it is on the page — but nothing here identifies anyone either.
     */
    public function runtimeConfig(Bar $bar): array
    {
        return [
            'id' => (int)$bar->id,
            'handle' => $bar->handle,
            'version' => (int)$bar->version,
            'position' => $bar->position,
            'display' => $bar->getDisplay()->forRuntime(),
            'targeting' => $bar->getTargeting()->forRuntime(),
        ];
    }

    /**
     * Refuses to emit a scheme that executes.
     *
     * Button URLs are author-written, and authors with the `manageBars` permission are trusted —
     * but a `javascript:` href is a stored payload that fires for every visitor, and there is no
     * legitimate bar that needs one. Anything unrecognised is emitted as a relative path, which
     * is inert.
     */
    public function safeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '#';
        }

        if (preg_match('/^(https?|mailto|tel|sms):/i', $url) || str_starts_with($url, '#')) {
            return $url;
        }

        // Any other scheme — `javascript:`, `data:`, `vbscript:` — is not a destination.
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url)) {
            return '#';
        }

        return $url;
    }

    public function baseCss(): string
    {
        return $this->baseCss ??= $this->resource('runtime.css');
    }

    public function runtimeJs(): string
    {
        return $this->runtimeJs ??= $this->resource('runtime.js');
    }

    /**
     * A bar drawn for the control panel, already visible and with no runtime attached.
     *
     * Scoped under a wrapper so the preview cannot escape its box and paint the control panel —
     * `position: fixed` on a bar in a settings screen would cover the screen it is being edited on.
     */
    public function previewHtml(Bar $bar): string
    {
        $selector = '.blaster-preview #' . $bar->domId();

        $css = $this->baseCss()
            . $bar->getTheme()->toCss($selector)
            . '.blaster-preview{position:relative;overflow:hidden}'
            . '.blaster-preview .blaster-bar{display:block;position:relative}';

        return '<div class="blaster-preview">'
            . '<style>' . $css . '</style>'
            . $this->renderBar($bar)
            . '</div>';
    }

    /**
     * Reads a file out of `src/resources`.
     *
     * Resolved from the plugin's own base path rather than through a Craft alias: these files are
     * inlined, never published, so nothing else has established that the alias exists.
     */
    private function resource(string $file): string
    {
        $path = Plugin::getInstance()->getBasePath() . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . $file;

        return is_file($path) ? (string)file_get_contents($path) : '';
    }

    public function markup(string $html): Markup
    {
        return new Markup($html, Craft::$app->charset);
    }
}
