<?php

namespace justinholtweb\blaster\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\blaster\elements\Bar;
use justinholtweb\blaster\Plugin;
use justinholtweb\blaster\services\Stats;
use yii\web\Response;

/**
 * The counting endpoint.
 *
 * Anonymous and CSRF-exempt, and both of those need justifying rather than assuming:
 *
 * - **Anonymous**, because visitors are anonymous. That is the point.
 * - **CSRF-exempt**, because the bar markup is spliced into pages that are very often cached, and
 *   a CSRF token baked into a cached page is a stale token served to everyone. There is nothing
 *   here worth forging: the endpoint adds one to a counter and can do nothing else. Someone
 *   determined to inflate a number can do so, and the worst outcome is a wrong number in a
 *   dashboard — which is a smaller problem than a token that leaks session state into a page
 *   cache.
 *
 * It accepts `sendBeacon` posts, which means it must answer fast and must never throw.
 *
 * What it does bound: only live bars are counted, and one address gets {@see RATE_LIMIT} hits a
 * minute. A page with a few bars sends a view each and the odd click or dismissal, so that ceiling
 * never touches a real visitor — but a loop posting as fast as it can stops costing an element
 * query and an upsert per request after the first few dozen.
 */
class TrackController extends Controller
{
    public array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    private const RATE_LIMIT = 60;

    public function actionHit(): Response
    {
        $this->requirePostRequest();

        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->trackStats) {
            return $this->asRaw('');
        }

        if ($settings->respectDoNotTrack && Craft::$app->getRequest()->getHeaders()->get('DNT') === '1') {
            return $this->asRaw('');
        }

        $request = Craft::$app->getRequest();
        $barId = (int)$request->getBodyParam('barId');
        $type = (string)$request->getBodyParam('type');
        $siteId = (int)$request->getBodyParam('siteId');

        if ($barId <= 0 || !Stats::isValidType($type)) {
            return $this->asRaw('');
        }

        // The posted site has to be a real one, or a typo'd beacon writes rows the foreign key
        // will reject and the daily upsert will retry forever.
        if (!$siteId || !Craft::$app->getSites()->getSiteById($siteId)) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        if (!$this->withinRateLimit()) {
            return $this->asRaw('');
        }

        // Only a bar a visitor could actually be shown. A disabled, pending or expired one has no
        // views to count — and counting them would let a script fill a draft's stats in advance.
        $bar = Plugin::getInstance()->bars->getBarById($barId, $siteId);

        if ($bar === null || $bar->getStatus() !== Bar::STATUS_LIVE) {
            return $this->asRaw('');
        }

        Plugin::getInstance()->stats->record($barId, $siteId, $type);

        // Deliberately empty: `sendBeacon` discards the response, and there is nothing to say.
        return $this->asRaw('');
    }

    /**
     * One address, {@see RATE_LIMIT} hits a minute.
     *
     * The minute is part of the key, so each window starts from zero, and the read and write are
     * held under a mutex so that requests sent in parallel cannot all read the same count. If the
     * lock is busy the hit is dropped rather than waited for: this is a beacon.
     */
    private function withinRateLimit(): bool
    {
        $key = 'blaster:rate:' . sha1((string)Craft::$app->getRequest()->getUserIP()) . ':' . intdiv(time(), 60);
        $cache = Craft::$app->getCache();
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($key, 1)) {
            return false;
        }

        try {
            $count = (int)$cache->get($key);

            if ($count >= self::RATE_LIMIT) {
                return false;
            }

            $cache->set($key, $count + 1, 120);

            return true;
        } finally {
            $mutex->release($key);
        }
    }
}
