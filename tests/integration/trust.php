<?php
/**
 * The anonymous counting endpoint, checked over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-blaster/tests/integration/trust.php
 *
 * `checks.php` records stats through the service; this posts to `blaster/track/hit` the way a
 * visitor (or a script) would, to check what the controller lets through. Idempotent and
 * self-cleaning: the two bars are hard-deleted, which takes their stats rows with them.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use justinholtweb\blaster\elements\Bar;
use justinholtweb\blaster\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

$plugin = Plugin::getInstance();
$site = Craft::$app->getSites()->getPrimarySite();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$created = [];

// The rate bucket for the local address, cleared before and after so back-to-back runs agree. The
// key shape mirrors TrackController::withinRateLimit().
$clearRateBuckets = static function(): void {
    foreach (['127.0.0.1', '::1'] as $ip) {
        foreach ([0, 1] as $back) {
            Craft::$app->getCache()->delete('blaster:rate:' . sha1($ip) . ':' . (intdiv(time(), 60) - $back));
        }
    }
};

register_shutdown_function(function() use (&$created, $clearRateBuckets) {
    foreach ($created as $bar) {
        Craft::$app->getElements()->deleteElement($bar, true);
    }

    $clearRateBuckets();
});

$makeBar = static function(string $name, bool $enabled) use ($plugin, $site, $run, &$created): Bar {
    $bar = new Bar();
    $bar->siteId = $site->id;
    $bar->title = "Trust $name $run";
    $bar->handle = "trust-$name-$run";
    $bar->position = Bar::POSITION_TOP;
    $bar->enabled = $enabled;
    $bar->setContent(['message' => '<p>Trust</p>']);

    if (!$plugin->bars->saveBar($bar)) {
        throw new RuntimeException(json_encode($bar->getErrors()));
    }

    $created[] = $bar;

    return $bar;
};

$live = $makeBar('live', true);
$disabled = $makeBar('disabled', false);

$http = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]);
$hit = static fn(Bar $bar) => $http->postAsync('index.php?p=actions/blaster/track/hit', [
    'form_params' => ['barId' => $bar->id, 'siteId' => $bar->siteId, 'type' => 'view'],
]);
// With a site ID, because the site-less totals are memoized for the life of the process and this
// reads them before and after the burst.
$views = static fn(Bar $bar) => (int)Plugin::getInstance()->stats->totalsForBar($bar->id, $bar->siteId)['views'];

echo "\nThe counting endpoint\n";

$clearRateBuckets();

check('a hit on a live bar is counted', function() use ($hit, $views, $live) {
    $hit($live)->wait();

    return $views($live) === 1 ?: 'views=' . $views($live);
});

check('a hit on a disabled bar is not', function() use ($hit, $views, $disabled) {
    $hit($disabled)->wait();

    return $views($disabled) === 0 ?: 'views=' . $views($disabled);
});

check('one address cannot count more than the limit in a minute, even in parallel', function() use ($http, $hit, $views, $live) {
    // Leave room if this minute is nearly over, so the whole burst lands in one window.
    if ((int)date('s') > 50) {
        sleep(61 - (int)date('s'));
    }

    $before = $views($live);

    $requests = static function() use ($hit, $live) {
        for ($i = 0; $i < 100; $i++) {
            yield fn() => $hit($live);
        }
    };

    (new Pool($http, $requests(), ['concurrency' => 20]))->promise()->wait();

    $counted = $views($live) - $before;

    // 60 a minute, of which the first check above already used some.
    echo "    (counted $counted of 100)\n";

    return $counted > 0 && $counted <= 60 ?: "100 hits counted $counted";
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
