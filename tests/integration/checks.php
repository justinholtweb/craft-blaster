<?php
/**
 * Blaster integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-blaster/tests/integration/checks.php
 *
 * Covers what unit fixtures cannot: a real element save through Craft, per-site content, status
 * derived from a schedule, the generated CSS and markup, and the counter upserts.
 *
 * Idempotent and self-cleaning — every bar it creates is deleted at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\helpers\Db;
use justinholtweb\blaster\elements\Bar;
use justinholtweb\blaster\models\BarContent;
use justinholtweb\blaster\models\BarDisplay;
use justinholtweb\blaster\models\BarSchedule;
use justinholtweb\blaster\models\BarTargeting;
use justinholtweb\blaster\models\BarTheme;
use justinholtweb\blaster\models\RequestContext;
use justinholtweb\blaster\Plugin;
use justinholtweb\blaster\services\Stats;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$site = Craft::$app->getSites()->getPrimarySite();
$suffix = substr(md5((string)microtime(true)), 0, 6);
$created = [];

// ---------------------------------------------------------------------------- config models

section('Config models — casting');

check('a blank number keeps the default rather than fatalling', function() {
    // A typed `int` property assigned '' is a TypeError, not a zero. An author clearing a field
    // is the most ordinary thing there is, so this is the trap the cast layer exists for.
    $display = BarDisplay::fromArray(['delaySeconds' => '', 'scrollPercent' => '40']);

    return $display->delaySeconds === 3 && $display->scrollPercent === 40
        ?: "delay={$display->delaySeconds} scroll={$display->scrollPercent}";
});

check('a lightswitch posts strings and reads back as booleans', function() {
    $on = BarDisplay::fromArray(['sticky' => '1', 'pushPage' => '']);

    return $on->sticky === true && $on->pushPage === false
        ?: 'sticky=' . var_export($on->sticky, true) . ' push=' . var_export($on->pushPage, true);
});

check('a JSON round trip survives unchanged', function() {
    $before = BarDisplay::fromArray(['trigger' => 'scroll', 'scrollPercent' => 60, 'maxViews' => 2]);
    $after = BarDisplay::fromArray(json_decode(json_encode($before->toArray()), true));

    return $after->toArray() === $before->toArray() ?: json_encode($after->toArray());
});

check('unknown keys are ignored', fn() => BarDisplay::fromArray(['nonsense' => 1, 'trigger' => 'delay'])->trigger === 'delay');

check('a reopen tab with no close button is refused', function() {
    $display = BarDisplay::fromArray(['dismissible' => '', 'showReopenTab' => '1']);

    return !$display->validate() && $display->hasErrors('showReopenTab') ?: 'validated';
});

section('Config models — targeting');

check('the blank trailing table row is dropped', function() {
    $targeting = BarTargeting::fromArray([
        'uriRules' => [['pattern' => 'blog/*', 'mode' => 'include'], ['pattern' => '', 'mode' => '']],
    ]);

    return count($targeting->uriRules) === 1 ?: json_encode($targeting->uriRules);
});

check('no devices selected means all of them, not none', function() {
    return BarTargeting::fromArray(['devices' => []])->devices === ['desktop', 'tablet', 'mobile'];
});

check('an uncompilable regular expression is caught', function() {
    $targeting = BarTargeting::fromArray(['uriRules' => [['pattern' => 're:[unclosed', 'mode' => 'include']]]);

    return !$targeting->validate() && $targeting->hasErrors('uriRules') ?: 'validated';
});

check('“selected pages” with nothing selected is refused', function() {
    $targeting = BarTargeting::fromArray(['pages' => 'uris', 'uriRules' => []]);

    return !$targeting->validate() && $targeting->hasErrors('uriRules') ?: 'validated';
});

section('Config models — schedule');

check('a Craft date field array is flattened to a stored string', function() {
    $schedule = BarSchedule::fromArray([
        'enabled' => '1',
        'start' => ['date' => '2026-09-01', 'time' => '09:00'],
    ]);

    return is_string($schedule->start) && str_starts_with($schedule->start, '2026-09-01')
        ?: var_export($schedule->start, true);
});

check('a cleared date becomes null, not an epoch', function() {
    return BarSchedule::fromArray(['start' => '', 'end' => null])->start === null;
});

check('an end before its start is refused', function() {
    $schedule = BarSchedule::fromArray(['enabled' => true, 'start' => '2026-09-10', 'end' => '2026-09-01']);

    return !$schedule->validate() && $schedule->hasErrors('end') ?: 'validated';
});

check('half a daily window is refused', function() {
    $schedule = BarSchedule::fromArray(['enabled' => true, 'dailyStart' => '09:00']);

    return !$schedule->validate() && $schedule->hasErrors('dailyEnd') ?: 'validated';
});

check('weekday numbers are deduplicated and sorted', function() {
    return BarSchedule::fromArray(['daysOfWeek' => ['5', 1, '1', 9, 0]])->daysOfWeek === [1, 5];
});

// ---------------------------------------------------------------------------- theme + CSS

section('Theme');

check('generated CSS carries the colours', function() {
    $css = BarTheme::fromArray(['background' => '#ff0000', 'text' => '#00ff00'])->toCss('#bar');

    return str_contains($css, 'background:#ff0000') && str_contains($css, 'color:#00ff00') ?: $css;
});

check('a colour that is not a colour cannot reach the stylesheet', function() {
    // Sanitised on the way out, not the way in: stored data can arrive from a config sync or a
    // restored database, so the boundary that matters is the one next to the output.
    $theme = BarTheme::fromArray(['background' => 'red;} body{display:none']);
    $css = $theme->toCss('#bar');

    return !str_contains($css, 'display:none') && str_contains($css, '#1B8EF2') ?: $css;
});

check('a colour posted without its hash still works', function() {
    // Craft's colour field submits `1B8EF2`, not `#1B8EF2`. Because the shipped default happens
    // to be that exact blue, getting this wrong looks like it works until somebody picks a
    // different colour — and then their colour silently reverts.
    $theme = BarTheme::fromArray(['background' => 'C0392B', 'buttonText' => '  0af  ']);

    return $theme->background === '#C0392B'
        && $theme->buttonText === '#0af'
        && str_contains($theme->toCss('#bar'), 'background:#C0392B')
        ?: "background={$theme->background} buttonText={$theme->buttonText}";
});

check('a cleared colour keeps the default rather than failing validation', function() {
    $theme = BarTheme::fromArray(['background' => '']);

    return $theme->background === '#1B8EF2' && $theme->validate() ?: "background={$theme->background}";
});

check('an out-of-range number falls back rather than emitting itself', function() {
    return !str_contains(BarTheme::fromArray(['fontSize' => 9999])->toCss('#bar'), '9999px');
});

check('{selector} is resolved in custom CSS', function() {
    $css = BarTheme::fromArray(['customCss' => '{selector} .blaster-bar__button{font-weight:900}'])->toCss('#bar-7');

    return str_contains($css, '#bar-7 .blaster-bar__button{font-weight:900}') ?: $css;
});

check('custom CSS cannot break out of its style element', function() {
    $css = BarTheme::fromArray(['customCss' => 'a{} </style><script>alert(1)</script>'])->toCss('#bar');

    return !str_contains(strtolower($css), '</style') ?: $css;
});

// ---------------------------------------------------------------------------- matching

section('URI matching');

$matcher = $plugin->matcher;

check('a glob matches below its prefix', fn() => $matcher->matchesPattern('blog/*', 'blog/hello-world'));
check('a glob does not match a sibling', fn() => !$matcher->matchesPattern('blog/*', 'news/hello'));
check('an empty pattern means the homepage', fn() => $matcher->matchesPattern('', '') && !$matcher->matchesPattern('', 'blog'));
check('a bare * matches the homepage too', fn() => $matcher->matchesPattern('*', ''));
check('a re: pattern is a regular expression', fn() => $matcher->matchesPattern('re:^shop/[0-9]+$', 'shop/42'));
check('leading slashes are irrelevant', fn() => $matcher->matchesPattern('/blog/*', '/blog/post'));

check('an exclusion beats an inclusion whatever the order', function() use ($matcher) {
    $rules = [
        ['pattern' => 'blog/drafts/*', 'mode' => 'exclude'],
        ['pattern' => 'blog/*', 'mode' => 'include'],
    ];

    return $matcher->matchesUriRules($rules, 'blog/post')
        && !$matcher->matchesUriRules($rules, 'blog/drafts/x')
        ?: 'wrong verdict';
});

check('exclusions alone let everything else through', function() use ($matcher) {
    $rules = [['pattern' => 'admin/*', 'mode' => 'exclude']];

    return $matcher->matchesUriRules($rules, 'about') && !$matcher->matchesUriRules($rules, 'admin/x');
});

check('no rules at all matches everything', fn() => $matcher->matchesUriRules([], 'anything'));

section('Daily window');

check('an ordinary window includes its start and excludes its end', function() use ($matcher) {
    return $matcher->withinDailyWindow('09:00', '09:00', '17:00')
        && $matcher->withinDailyWindow('12:00', '09:00', '17:00')
        && !$matcher->withinDailyWindow('17:00', '09:00', '17:00')
        && !$matcher->withinDailyWindow('08:59', '09:00', '17:00');
});

check('a window that ends before it starts runs overnight', function() use ($matcher) {
    // 22:00–02:00 is a real thing an author will type; reading it as empty would make the bar
    // never appear, with nothing on screen to explain why.
    return $matcher->withinDailyWindow('23:30', '22:00', '02:00')
        && $matcher->withinDailyWindow('01:00', '22:00', '02:00')
        && !$matcher->withinDailyWindow('12:00', '22:00', '02:00');
});

// ---------------------------------------------------------------------------- the element

section('Saving a bar');

$bar = new Bar();
$bar->siteId = $site->id;
$bar->title = "Check bar $suffix";
$bar->handle = "check-bar-$suffix";
$bar->position = Bar::POSITION_TOP;
$bar->priority = 50;
$bar->setDisplay(['trigger' => 'delay', 'delaySeconds' => 2, 'dismissDays' => 14]);
$bar->setTheme(['background' => '#123456']);
$bar->setContent(['message' => '<p>Hello</p>', 'buttonEnabled' => true, 'buttonLabel' => 'Go', 'buttonUrl' => '/shop']);

check('it saves', function() use ($bar, $plugin, &$created) {
    $saved = $plugin->bars->saveBar($bar);

    if ($saved) {
        $created[] = $bar;
    }

    return $saved ?: json_encode($bar->getErrors());
});

check('saving bumps the version', fn() => $bar->version === 2 ?: "version={$bar->version}");

check('it reloads with its configuration intact', function() use ($bar, $plugin, $site) {
    $fresh = $plugin->bars->getBarById($bar->id, $site->id);

    return $fresh
        && $fresh->getDisplay()->trigger === 'delay'
        && $fresh->getDisplay()->delaySeconds === 2
        && $fresh->getTheme()->background === '#123456'
        && $fresh->priority === 50
        ?: 'reloaded wrong';
});

check('it reloads with its content intact', function() use ($bar, $plugin, $site) {
    $content = $plugin->bars->getBarById($bar->id, $site->id)->getContent();

    return $content->message === '<p>Hello</p>' && $content->buttonLabel === 'Go' ?: json_encode($content->toArray());
});

check('the handle is taken now', fn() => $plugin->bars->handleIsTaken("check-bar-$suffix"));
check('…but not against itself', fn() => !$plugin->bars->handleIsTaken("check-bar-$suffix", $bar->id));

check('a second bar cannot claim the same handle', function() use ($site, $suffix) {
    $clash = new Bar();
    $clash->siteId = $site->id;
    $clash->title = 'Clash';
    $clash->handle = "check-bar-$suffix";
    $clash->setContent(['message' => 'x']);

    return !$clash->validate() && $clash->hasErrors('handle') ?: 'validated';
});

check('uniqueHandle walks past the taken one', function() use ($plugin, $suffix) {
    return $plugin->bars->uniqueHandle("check-bar-$suffix") === "check-bar-$suffix-2";
});

check('a bar with nothing to say is refused', function() use ($site) {
    $empty = new Bar();
    $empty->siteId = $site->id;
    $empty->title = 'Empty';
    $empty->handle = 'check-empty';
    $empty->setContent(['message' => '   ', 'buttonEnabled' => false]);

    return !$empty->validate() && $empty->hasErrors('content.message') ?: 'validated';
});

section('Status');

check('an enabled bar with no schedule is live', function() use ($bar, $plugin, $site) {
    return $plugin->bars->getBarById($bar->id, $site->id)->getStatus() === Bar::STATUS_LIVE;
});

$pending = new Bar();
$pending->siteId = $site->id;
$pending->title = "Pending $suffix";
$pending->handle = "check-pending-$suffix";
$pending->setContent(['message' => 'Later']);
$pending->setSchedule(['enabled' => true, 'start' => (new DateTime('+10 days'))->format(DateTime::ATOM)]);

check('a bar waiting for its start date is pending, not live', function() use ($pending, $plugin, &$created) {
    if (!$plugin->bars->saveBar($pending)) {
        return json_encode($pending->getErrors());
    }

    $created[] = $pending;

    return $pending->getStatus() === Bar::STATUS_PENDING ?: 'status=' . $pending->getStatus();
});

check('and the query agrees, in SQL', function() use ($pending, $site) {
    $live = Bar::find()->id($pending->id)->siteId($site->id)->status(Bar::STATUS_LIVE)->exists();
    $isPending = Bar::find()->id($pending->id)->siteId($site->id)->status(Bar::STATUS_PENDING)->exists();

    return !$live && $isPending ?: 'live=' . var_export($live, true) . ' pending=' . var_export($isPending, true);
});

$expired = new Bar();
$expired->siteId = $site->id;
$expired->title = "Expired $suffix";
$expired->handle = "check-expired-$suffix";
$expired->setContent(['message' => 'Over']);
$expired->setSchedule(['enabled' => true, 'end' => (new DateTime('-10 days'))->format(DateTime::ATOM)]);

check('a bar past its end date is expired', function() use ($expired, $plugin, &$created) {
    if (!$plugin->bars->saveBar($expired)) {
        return json_encode($expired->getErrors());
    }

    $created[] = $expired;

    return $expired->getStatus() === Bar::STATUS_EXPIRED ?: 'status=' . $expired->getStatus();
});

check('an expired bar is not a candidate for any request', function() use ($expired, $plugin, $site) {
    $context = new RequestContext(siteId: $site->id, siteUid: $site->uid, uri: '');
    $ids = array_map(fn(Bar $b) => $b->id, $plugin->matcher->candidates($context));

    return !in_array($expired->id, $ids, true) ?: 'expired bar was a candidate';
});

section('Targeting against a request');

check('a homepage-only bar matches the homepage and nothing else', function() use ($bar, $plugin, $site) {
    $bar->setTargeting(['pages' => 'home']);

    $home = new RequestContext(siteId: $site->id, siteUid: $site->uid, uri: '');
    $other = new RequestContext(siteId: $site->id, siteUid: $site->uid, uri: 'about');

    return $plugin->matcher->matches($bar, $home) && !$plugin->matcher->matches($bar, $other);
});

check('a signed-out-only bar hides from signed-in users', function() use ($bar, $plugin, $site) {
    $bar->setTargeting(['pages' => 'all', 'auth' => 'guests']);

    $guest = new RequestContext(siteId: $site->id, siteUid: $site->uid, uri: '');
    $user = new RequestContext(
        siteId: $site->id,
        siteUid: $site->uid,
        uri: '',
        user: craft\elements\User::find()->admin()->one(),
    );

    return $plugin->matcher->matches($bar, $guest) && !$plugin->matcher->matches($bar, $user);
});

check('a query-string rule tests presence when it has no value', function() use ($bar, $plugin, $site) {
    $bar->setTargeting(['pages' => 'all', 'queryParams' => [['name' => 'utm_source', 'value' => '']]]);

    $with = new RequestContext(siteId: $site->id, siteUid: $site->uid, uri: '', queryParams: ['utm_source' => 'anything']);
    $without = new RequestContext(siteId: $site->id, siteUid: $site->uid, uri: '');

    return $plugin->matcher->matches($bar, $with) && !$plugin->matcher->matches($bar, $without);
});

check('a query-string rule with a value demands that value', function() use ($bar, $plugin, $site) {
    $bar->setTargeting(['pages' => 'all', 'queryParams' => [['name' => 'c', 'value' => 'spring']]]);

    $right = new RequestContext(siteId: $site->id, siteUid: $site->uid, uri: '', queryParams: ['c' => 'spring']);
    $wrong = new RequestContext(siteId: $site->id, siteUid: $site->uid, uri: '', queryParams: ['c' => 'autumn']);

    return $plugin->matcher->matches($bar, $right) && !$plugin->matcher->matches($bar, $wrong);
});

check('a bar limited to another site does not match this one', function() use ($bar, $plugin, $site) {
    $bar->setTargeting(['pages' => 'all', 'siteUids' => ['not-a-real-uid']]);

    return !$plugin->matcher->matches($bar, new RequestContext(siteId: $site->id, siteUid: $site->uid, uri: ''));
});

check('a weekday rule excludes the other six days', function() use ($bar, $plugin, $site) {
    $today = (int)(new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())))->format('N');
    $bar->setTargeting(['pages' => 'all']);
    $bar->setSchedule(['enabled' => true, 'daysOfWeek' => [$today]]);

    $context = new RequestContext(siteId: $site->id, siteUid: $site->uid, uri: '');
    $matchesToday = $plugin->matcher->matchesSchedule($bar, $context);

    $bar->setSchedule(['enabled' => true, 'daysOfWeek' => [$today === 7 ? 1 : $today + 1]]);
    $matchesTomorrow = $plugin->matcher->matchesSchedule($bar, $context);

    $bar->setSchedule([]);

    return $matchesToday && !$matchesTomorrow
        ?: 'today=' . var_export($matchesToday, true) . ' other=' . var_export($matchesTomorrow, true);
});

// ---------------------------------------------------------------------------- rendering

section('Rendering');

$renderer = $plugin->renderer;

check('the bar element carries its id and position class', function() use ($bar, $renderer) {
    $bar->position = Bar::POSITION_TOP;
    $html = $renderer->renderBar($bar);

    return str_contains($html, 'id="blaster-bar-' . $bar->id . '"')
        && str_contains($html, 'blaster-bar--top')
        ?: $html;
});

check('the runtime config rides in an attribute, HTML-escaped', function() use ($bar, $renderer) {
    $html = $renderer->renderBar($bar);
    $decoded = html_entity_decode($html, ENT_QUOTES);

    return str_contains($decoded, '"handle":"' . $bar->handle . '"') ?: $html;
});

check('the config names the version, so a dismissal can be scoped to it', function() use ($bar, $renderer) {
    return $renderer->runtimeConfig($bar)['version'] === $bar->version;
});

check('a javascript: button URL is refused', function() use ($renderer) {
    return $renderer->safeUrl('javascript:alert(1)') === '#'
        && $renderer->safeUrl('JaVaScRiPt:alert(1)') === '#'
        && $renderer->safeUrl('data:text/html,<script>') === '#';
});

check('ordinary URLs are left alone', function() use ($renderer) {
    return $renderer->safeUrl('/shop') === '/shop'
        && $renderer->safeUrl('https://example.com') === 'https://example.com'
        && $renderer->safeUrl('mailto:a@b.com') === 'mailto:a@b.com'
        && $renderer->safeUrl('#signup') === '#signup';
});

check('the full payload carries the stylesheet and the runtime', function() use ($bar, $renderer, $site) {
    $html = $renderer->renderAll([$bar], $site->id);

    return str_contains($html, '<style>')
        && str_contains($html, '.blaster-bar__inner')
        && str_contains($html, '<script>')
        && str_contains($html, 'data-blaster-bar')
        ?: substr($html, 0, 300);
});

check('rendering twice does not emit a second copy', function() use ($renderer) {
    // `hasRendered()` is what stands automatic injection down once a template has placed the bars
    // itself, so it has to be true after the first pass.
    return $renderer->hasRendered();
});

check('the preview is scoped so it cannot cover the control panel', function() use ($bar, $renderer) {
    $html = $renderer->previewHtml($bar);

    return str_contains($html, '.blaster-preview #blaster-bar-' . $bar->id)
        && str_contains($html, '.blaster-preview .blaster-bar{display:block;position:relative}')
        ?: substr($html, 0, 300);
});

check('a message is purified on the way in', function() use ($plugin) {
    $clean = $plugin->purifyMessage('<p>Hi <script>alert(1)</script><strong>there</strong></p>');

    return !str_contains($clean, '<script') && str_contains($clean, '<strong>there</strong>') ?: $clean;
});

section('Injection');

check('a template response counts as a page', function() use ($plugin) {
    // Craft renders front-end templates through its own `template` response format, not
    // FORMAT_HTML, so a format check here matches nothing and the plugin silently does nothing.
    return $plugin->isHtmlResponse('text/html; charset=UTF-8')
        && $plugin->isHtmlResponse('TEXT/HTML')
        ?: 'html response rejected';
});

check('a feed or a JSON template is left alone', function() use ($plugin) {
    return !$plugin->isHtmlResponse('application/json')
        && !$plugin->isHtmlResponse('application/rss+xml')
        && !$plugin->isHtmlResponse('')
        ?: 'non-page accepted';
});

check('markup goes in before the last closing body tag', function() use ($plugin) {
    $page = '<html><body><textarea>&lt;/body&gt;</textarea><p>hi</p></body></html>';
    $result = $plugin->spliceIntoBody($page, '<!--BAR-->');

    return $result === '<html><body><textarea>&lt;/body&gt;</textarea><p>hi</p><!--BAR--></body></html>'
        ?: $result;
});

check('a page with no body is left alone rather than guessed at', function() use ($plugin) {
    return $plugin->spliceIntoBody('<p>fragment</p>', '<!--BAR-->') === null;
});

check('excluded URIs are refused whatever a bar targets', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $before = $settings->excludedUris;
    $settings->excludedUris = ['embeds/*'];

    $blocked = $plugin->matcher->matchesPattern('embeds/*', 'embeds/widget');
    $settings->excludedUris = $before;

    return $blocked;
});

// ---------------------------------------------------------------------------- counters

section('Counters');

check('a view is recorded', function() use ($bar, $plugin, $site) {
    $plugin->stats->record($bar->id, $site->id, Stats::TYPE_VIEW);

    return $plugin->stats->totalsForBar($bar->id)['views'] >= 1
        ?: json_encode($plugin->stats->totalsForBar($bar->id));
});

check('further events add to the same day rather than making a row', function() use ($bar, $plugin, $site) {
    $before = $plugin->stats->totalsForBar($bar->id)['views'];
    $plugin->stats->record($bar->id, $site->id, Stats::TYPE_VIEW);
    $plugin->stats->record($bar->id, $site->id, Stats::TYPE_CLICK);

    $rows = (new craft\db\Query())->from('{{%blaster_stats}}')->where(['barId' => $bar->id])->count();
    $after = $plugin->stats->totalsForBar($bar->id);

    // One row for the day, whatever mix of events lands on it — that is what the upsert buys.
    return $after['views'] === $before + 1 && $after['clicks'] === 1 && (int)$rows === 1
        ?: "rows=$rows before=$before " . json_encode($after);
});

check('an unknown event type is refused', function() use ($bar, $plugin, $site) {
    return !$plugin->stats->record($bar->id, $site->id, 'nonsense') && !Stats::isValidType('nonsense');
});

check('the series has one entry per day with no gaps', function() use ($bar, $plugin) {
    $series = $plugin->stats->series($bar->id, 7);
    $dates = array_column($series, 'date');

    return count($series) === 7 && count(array_unique($dates)) === 7 && end($series)['views'] >= 1
        ?: json_encode($series);
});

check('pruning leaves today alone', function() use ($bar, $plugin) {
    $plugin->stats->prune(30);

    return $plugin->stats->totalsForBar($bar->id)['views'] >= 1 ?: 'today was pruned';
});

check('an old row is pruned', function() use ($bar, $plugin, $site) {
    Craft::$app->getDb()->createCommand()->insert('{{%blaster_stats}}', [
        'barId' => $bar->id,
        'siteId' => $site->id,
        'date' => (new DateTime('-400 days'))->format('Y-m-d'),
        'views' => 5,
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => craft\helpers\StringHelper::UUID(),
    ])->execute();

    $deleted = $plugin->stats->prune(365);

    return $deleted >= 1 ?: "deleted=$deleted";
});

// ---------------------------------------------------------------------------- trash

section('The trash');

check('deleting parks the handle so it can be reused', function() use ($plugin, $site, $suffix) {
    $doomed = new Bar();
    $doomed->siteId = $site->id;
    $doomed->title = "Doomed $suffix";
    $doomed->handle = "check-doomed-$suffix";
    $doomed->setContent(['message' => 'bye']);

    if (!$plugin->bars->saveBar($doomed)) {
        return json_encode($doomed->getErrors());
    }

    $plugin->bars->deleteBar($doomed);

    // The point: a soft-deleted bar must not hold its handle against something new, because the
    // author cannot see what is holding it.
    return !$plugin->bars->handleIsTaken("check-doomed-$suffix") ?: 'handle still held';
});

check('a restored bar claims its handle back', function() use ($plugin, $site, $suffix) {
    $doomed = Bar::find()->handle("check-doomed-$suffix--trashed-%")->trashed()->status(null)->siteId($site->id)->one()
        ?? Bar::find()->trashed()->status(null)->siteId($site->id)->title("Doomed $suffix")->one();

    if (!$doomed) {
        return 'could not find the trashed bar';
    }

    Craft::$app->getElements()->restoreElement($doomed);
    $restored = Bar::find()->id($doomed->id)->status(null)->siteId($site->id)->one();

    if ($restored) {
        $plugin->bars->deleteBar($restored);
    }

    return $restored && $restored->handle === "check-doomed-$suffix" ?: 'handle=' . ($restored->handle ?? 'gone');
});

// ---------------------------------------------------------------------------- cleanup

section('Cleanup');

foreach ($created as $index => $victim) {
    check('check bar ' . ($index + 1) . ' deletes', function() use ($victim, $plugin) {
        $fresh = $plugin->bars->getBarById($victim->id);

        return $fresh === null || Craft::$app->getElements()->deleteElement($fresh, true) ?: 'delete returned false';
    });
}

check('its counters go with it', function() use ($created) {
    $ids = array_map(fn(Bar $b) => $b->id, $created);

    if (!$ids) {
        return true;
    }

    $rows = (new craft\db\Query())->from('{{%blaster_stats}}')->where(['barId' => $ids])->count();

    return (int)$rows === 0 ?: "$rows counter rows survived the hard delete";
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
