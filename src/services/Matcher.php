<?php

namespace justinholtweb\blaster\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use DateTimeZone;
use justinholtweb\blaster\elements\Bar;
use justinholtweb\blaster\models\BarTargeting;
use justinholtweb\blaster\models\RequestContext;
use justinholtweb\blaster\Plugin;
use yii\helpers\StringHelper;

/**
 * Decides which bars a given request is allowed to receive.
 *
 * Only the *server-side* half of targeting lives here — the half that varies by request. The
 * half that varies by visitor (device, referrer, first visit, view caps, prior dismissals) is
 * deliberately not decided here; see `docs/plan.md`. The short version is that deciding those on
 * the server would mean either breaking page caches or keeping a record of the person, and
 * neither is worth it for a notification bar.
 *
 * So this returns *candidates*, not a winner. The runtime picks the winner.
 */
class Matcher extends Component
{
    /**
     * The bars this request may receive, highest priority first.
     *
     * @return Bar[]
     */
    public function candidates(?RequestContext $context = null): array
    {
        $context ??= RequestContext::fromRequest();
        $bars = Plugin::getInstance()->bars->getLiveBars($context->siteId);

        return array_values(array_filter(
            $bars,
            fn(Bar $bar) => $this->matches($bar, $context) && !$bar->getContent()->isEmpty(),
        ));
    }

    public function matches(Bar $bar, RequestContext $context): bool
    {
        $targeting = $bar->getTargeting();

        return $this->matchesSite($targeting, $context)
            && $this->matchesPages($targeting, $context)
            && $this->matchesAuth($targeting, $context)
            && $this->matchesQueryParams($targeting, $context)
            && $this->matchesSchedule($bar, $context);
    }

    private function matchesSite(BarTargeting $targeting, RequestContext $context): bool
    {
        return !$targeting->siteUids || in_array($context->siteUid, $targeting->siteUids, true);
    }

    private function matchesPages(BarTargeting $targeting, RequestContext $context): bool
    {
        return match ($targeting->pages) {
            BarTargeting::PAGES_HOME => $context->isHomepage(),
            BarTargeting::PAGES_URIS => $this->matchesUriRules($targeting->uriRules, $context->uri),
            BarTargeting::PAGES_ELEMENTS => $this->matchesElement($targeting, $context),
            default => true,
        };
    }

    /**
     * Applies the URI rules in the order they are written, with one asymmetry:
     * **an exclusion always wins**.
     *
     * Rules are read as "show it here, except there", which is how people write them and not what
     * a plain last-match-wins would do — `blog/*` followed by `blog/drafts/*` as an exclusion has
     * to exclude the drafts however the two are ordered on screen.
     */
    public function matchesUriRules(array $rules, string $uri): bool
    {
        if (!$rules) {
            return true;
        }

        $hasInclude = false;
        $included = false;

        foreach ($rules as $rule) {
            $matched = $this->matchesPattern((string)($rule['pattern'] ?? ''), $uri);

            if (($rule['mode'] ?? 'include') === 'exclude') {
                if ($matched) {
                    return false;
                }

                continue;
            }

            $hasInclude = true;
            $included = $included || $matched;
        }

        // Only exclusions written: everything they did not name is fair game.
        return !$hasInclude || $included;
    }

    /**
     * A glob, or a regular expression when the pattern starts with `re:`.
     *
     * The homepage is the empty string, so a bare `*` matches it; `` on its own matches only it.
     */
    public function matchesPattern(string $pattern, string $uri): bool
    {
        $pattern = trim($pattern);
        $uri = trim($uri, '/');

        if ($pattern === '') {
            return $uri === '';
        }

        if (str_starts_with($pattern, 're:')) {
            return (bool)@preg_match(BarTargeting::compileRegex($pattern), $uri);
        }

        return StringHelper::matchWildcard(trim($pattern, '/'), $uri, ['caseSensitive' => false]);
    }

    private function matchesElement(BarTargeting $targeting, RequestContext $context): bool
    {
        $element = $context->element;

        if ($element === null) {
            return false;
        }

        if ($targeting->entryIds && in_array((int)$element->id, $targeting->entryIds, true)) {
            return true;
        }

        if (!$targeting->sectionUids || !$element instanceof Entry) {
            return false;
        }

        // Craft 5 entries can live inside a Matrix field with no section at all.
        $section = $element->getSection();

        return $section !== null && in_array($section->uid, $targeting->sectionUids, true);
    }

    private function matchesAuth(BarTargeting $targeting, RequestContext $context): bool
    {
        return match ($targeting->auth) {
            BarTargeting::AUTH_GUESTS => $context->user === null,
            BarTargeting::AUTH_MEMBERS => $context->user !== null && $this->matchesGroups($targeting, $context),
            default => true,
        };
    }

    private function matchesGroups(BarTargeting $targeting, RequestContext $context): bool
    {
        if (!$targeting->userGroupUids) {
            return true;
        }

        foreach ($context->user->getGroups() as $group) {
            if (in_array($group->uid, $targeting->userGroupUids, true)) {
                return true;
            }
        }

        return false;
    }

    /** Every rule must hold. An empty value tests presence, which is what a `?utm_source` rule wants. */
    private function matchesQueryParams(BarTargeting $targeting, RequestContext $context): bool
    {
        foreach ($targeting->queryParams as $rule) {
            $name = (string)($rule['name'] ?? '');

            if (!array_key_exists($name, $context->queryParams)) {
                return false;
            }

            $expected = (string)($rule['value'] ?? '');

            if ($expected !== '' && $context->queryParams[$name] !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * The parts of the schedule that {@see \justinholtweb\blaster\elements\db\BarQuery} could not
     * do in SQL.
     *
     * The date range is already handled by the `live` status, so what is left is the recurring
     * shape: which weekdays, and which hours of those days. Both are read in the **site's** time
     * zone — the author typed "9am" meaning nine o'clock where the site lives, not UTC.
     */
    public function matchesSchedule(Bar $bar, RequestContext $context): bool
    {
        $schedule = $bar->getSchedule();

        if (!$schedule->enabled) {
            return true;
        }

        $now = $context->getNow();

        if ($schedule->isPending($now) || $schedule->isExpired($now)) {
            return false;
        }

        if (!$schedule->daysOfWeek && $schedule->dailyStart === null) {
            return true;
        }

        $local = (clone $now)->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));

        if ($schedule->daysOfWeek && !in_array((int)$local->format('N'), $schedule->daysOfWeek, true)) {
            return false;
        }

        if ($schedule->dailyStart === null || $schedule->dailyEnd === null) {
            return true;
        }

        return $this->withinDailyWindow($local->format('H:i'), $schedule->dailyStart, $schedule->dailyEnd);
    }

    /**
     * String comparison works because `H:i` sorts lexicographically in clock order.
     *
     * A window whose end is before its start wraps past midnight — 22:00–02:00 is a real thing an
     * author will type, and reading it as an empty window would make the bar never appear with no
     * indication why.
     */
    public function withinDailyWindow(string $now, string $start, string $end): bool
    {
        if ($start === $end) {
            return true;
        }

        return $start < $end
            ? $now >= $start && $now < $end
            : $now >= $start || $now < $end;
    }

    /**
     * Whether this response should receive bars at all.
     *
     * Separate from targeting: this is about the *response*, not the bar. A JSON endpoint, a CP
     * screen or an HTMX fragment can all be perfectly valid matches for a bar's targeting and
     * still be the wrong place to splice a `<div>` into.
     */
    public function requestIsEligible(): bool
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return false;
        }

        if ($request->getIsCpRequest() || $request->getIsActionRequest() || $request->getIsAjax()) {
            return false;
        }

        if ($request->getIsPreview()) {
            return true;
        }

        $uri = trim($request->getPathInfo(), '/');

        foreach (Plugin::getInstance()->getSettings()->excludedUris as $pattern) {
            if ($this->matchesPattern((string)$pattern, $uri)) {
                return false;
            }
        }

        return true;
    }
}
