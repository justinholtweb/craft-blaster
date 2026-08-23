<?php

namespace justinholtweb\blaster;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\FileHelper;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use HTMLPurifier;
use HTMLPurifier_Config;
use justinholtweb\blaster\elements\Bar;
use justinholtweb\blaster\models\Settings;
use justinholtweb\blaster\services\Bars;
use justinholtweb\blaster\services\Matcher;
use justinholtweb\blaster\services\Renderer;
use justinholtweb\blaster\services\Stats;
use justinholtweb\blaster\twig\BlasterVariable;
use Throwable;
use yii\base\Event;

/**
 * Blaster — notification bars for Craft.
 *
 * @property-read Bars $bars
 * @property-read Matcher $matcher
 * @property-read Renderer $renderer
 * @property-read Stats $stats
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_VIEW = 'blaster:viewBars';
    public const PERMISSION_MANAGE = 'blaster:manageBars';
    public const PERMISSION_DELETE = 'blaster:deleteBars';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'blaster';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'bars' => Bars::class,
                'matcher' => Matcher::class,
                'renderer' => Renderer::class,
                'stats' => Stats::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerGarbageCollection();
        $this->registerInjection();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('blaster', 'Blaster');

        $item['subnav'] = [
            'bars' => [
                'label' => Craft::t('blaster', 'Bars'),
                'url' => 'blaster/bars',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('blaster', 'Settings'),
                'url' => 'settings/plugins/blaster',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('blaster/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'purifierConfigs' => $this->purifierConfigOptions(),
        ]);
    }

    // ------------------------------------------------------------------ injection

    /**
     * Splices matching bars into front-end HTML responses.
     *
     * Done by rewriting the prepared response body rather than by hooking `View::EVENT_END_BODY`,
     * because that hook only fires for templates that call `{{ endBody() }}` — plenty of real
     * sites do not, and a plugin whose main feature silently does nothing on those sites is worse
     * than one that asks for a template change up front.
     *
     * The trade is that the markup is spliced into a string. So this is careful about what it
     * touches: HTML only, success responses only, and only when there is a `</body>` to splice
     * before. Anything else is left exactly as it was.
     */
    private function registerInjection(): void
    {
        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
            try {
                $this->inject($event->sender);
            } catch (Throwable $e) {
                // A notification bar is never worth taking a page down for.
                Craft::error('Could not inject bars: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    private function inject(Response $response): void
    {
        if (!$this->getSettings()->autoInject || $this->renderer->hasRendered()) {
            return;
        }

        if ($response->getStatusCode() >= 500) {
            return;
        }

        if (!$this->isHtmlResponse((string)$response->getHeaders()->get('content-type'))) {
            return;
        }

        $html = $response->content;

        if (!is_string($html) || $html === '' || !$this->hasBodyToSplice($html)) {
            return;
        }

        if (!$this->matcher->requestIsEligible()) {
            return;
        }

        $bars = $this->matcher->candidates();

        if (!$bars) {
            return;
        }

        $spliced = $this->spliceIntoBody($html, $this->renderer->renderAll($bars));

        if ($spliced === null) {
            return;
        }

        $response->content = $spliced;

        // `sendContentLengthHeader` makes Craft stamp the length during prepare — before this
        // runs. Leaving it stale truncates the page at exactly the byte the bar was added at,
        // which looks like a broken template rather than a broken header.
        $headers = $response->getHeaders();

        if ($headers->get('content-length') !== null) {
            $headers->set('content-length', (string)strlen($response->content));
        }
    }

    /**
     * Whether a prepared response is a page.
     *
     * The content *type* answers this; the response format does not. Craft renders front-end
     * templates through its own `template` format rather than `FORMAT_HTML`, and that formatter
     * sets the MIME type from the template's file extension — so `feed.rss.twig` and
     * `manifest.json.twig` are template responses that must be left alone. Testing the format
     * instead matches nothing at all, which is a plugin that silently never works.
     */
    public function isHtmlResponse(string $contentType): bool
    {
        return str_contains(strtolower($contentType), 'text/html');
    }

    public function hasBodyToSplice(string $html): bool
    {
        return stripos($html, '</body>') !== false;
    }

    /**
     * Puts the markup immediately before the page's last closing body tag.
     *
     * The *last* one, because a page may perfectly legitimately contain the string earlier — in a
     * code sample, in an escaped snippet, in a `<textarea>` holding markup someone is editing.
     */
    public function spliceIntoBody(string $html, string $markup): ?string
    {
        $position = strripos($html, '</body>');

        return $position === false ? null : substr_replace($html, $markup, $position, 0);
    }

    // ------------------------------------------------------------------ content cleaning

    /**
     * Cleans a bar's message.
     *
     * Run once on save rather than on every render: the message is served on every page of the
     * site, and paying for HTML Purifier per request to clean bytes that have not changed since
     * they were typed is a cost with nothing on the other side of it.
     */
    public function purifyMessage(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        return (new HTMLPurifier($this->purifierConfig()))->purify($html);
    }

    public function purifierConfig(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->autoFinalize = false;

        $options = [
            'Attr.AllowedFrameTargets' => ['_blank'],
            'Attr.AllowedRel' => ['noopener', 'noreferrer', 'nofollow'],
            'HTML.Allowed' => 'p,br,strong,b,em,i,u,s,span[class],a[href|title|target|rel],ul,ol,li,small,code',
        ];

        $file = $this->getSettings()->purifierConfig;

        if ($file) {
            $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'htmlpurifier' . DIRECTORY_SEPARATOR . $file . '.json';

            if (is_file($path)) {
                $decoded = json_decode(file_get_contents($path), true);

                if (is_array($decoded)) {
                    $options = $decoded;
                }
            }
        }

        foreach ($options as $option => $value) {
            $config->set($option, $value);
        }

        return $config;
    }

    /** @return array<string, string> */
    public function purifierConfigOptions(): array
    {
        $options = ['' => Craft::t('blaster', 'Default')];
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'htmlpurifier';

        if (is_dir($path)) {
            foreach (FileHelper::findFiles($path, ['only' => ['*.json'], 'recursive' => false]) as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $options[$name] = $name;
            }
        }

        return $options;
    }

    // ------------------------------------------------------------------ registration

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Bar::class;
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'blaster' => 'blaster/bars/index',
                'blaster/bars' => 'blaster/bars/index',
                'blaster/bars/new' => 'blaster/bars/edit',
                'blaster/bars/<barId:\d+>' => 'blaster/bars/edit',
                'blaster/bars/<barId:\d+>/<siteHandle:{handle}>' => 'blaster/bars/edit',
            ];
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('blaster', 'Blaster'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('blaster', 'View bars'),
                        'nested' => [
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('blaster', 'Create and edit bars'),
                            ],
                            self::PERMISSION_DELETE => [
                                'label' => Craft::t('blaster', 'Delete bars'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('blaster', BlasterVariable::class);
        });
    }

    /**
     * Counters are keyed on a bar id with a cascading foreign key, so an ordinary delete cleans
     * up after itself. This catches the case the key cannot: rows whose bar disappeared without
     * the database being told — a restored backup, a hand-truncated table, a botched merge.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->stats->collectGarbage();
            $this->stats->prune();
        });
    }
}
