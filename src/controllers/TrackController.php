<?php

namespace justinholtweb\blaster\controllers;

use Craft;
use craft\web\Controller;
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
 */
class TrackController extends Controller
{
    public array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

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

        if (Plugin::getInstance()->bars->getBarById($barId, $siteId) === null) {
            return $this->asRaw('');
        }

        Plugin::getInstance()->stats->record($barId, $siteId, $type);

        // Deliberately empty: `sendBeacon` discards the response, and there is nothing to say.
        return $this->asRaw('');
    }
}
