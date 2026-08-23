<?php

namespace justinholtweb\blaster\models;

use Craft;

/**
 * Who sees a bar, and where.
 *
 * The properties fall into two groups, and the split is deliberate — see `docs/plan.md`.
 *
 * **Server-side** ({@see forServer()}) is anything that varies per *request*: the URI, the
 * matched element, the site, whether someone is signed in and to which group, the query string.
 * {@see \justinholtweb\blaster\services\Matcher} decides these, and a bar that fails is never
 * sent.
 *
 * **Client-side** ({@see forRuntime()}) is anything that varies per *visitor*: device width,
 * where they came from, whether they have been here before. Deciding those on the server would
 * mean either defeating page caching or keeping a record of the person, so the browser decides
 * instead and nothing is stored anywhere but their own machine.
 */
class BarTargeting extends ConfigModel
{
    public const PAGES_ALL = 'all';
    public const PAGES_HOME = 'home';
    public const PAGES_URIS = 'uris';
    public const PAGES_ELEMENTS = 'elements';

    public const AUTH_ANY = 'any';
    public const AUTH_GUESTS = 'guests';
    public const AUTH_MEMBERS = 'members';

    public const VISITOR_ANY = 'any';
    public const VISITOR_FIRST = 'first';
    public const VISITOR_RETURNING = 'returning';

    public const REFERRER_ANY = 'any';
    public const REFERRER_DIRECT = 'direct';
    public const REFERRER_SEARCH = 'search';
    public const REFERRER_EXTERNAL = 'external';
    public const REFERRER_DOMAINS = 'domains';

    public const DEVICE_DESKTOP = 'desktop';
    public const DEVICE_TABLET = 'tablet';
    public const DEVICE_MOBILE = 'mobile';

    // ---------------------------------------------------------------- server-side

    public string $pages = self::PAGES_ALL;

    /**
     * URI rules, applied in order, each `['pattern' => string, 'mode' => 'include'|'exclude']`.
     *
     * A pattern is a glob (`blog/*`) unless it starts with `re:`, in which case the remainder is
     * a regular expression. `` matches the homepage, mirroring Craft's own routing.
     */
    public array $uriRules = [];

    /** Section UIDs the matched entry must belong to. Empty means any. */
    public array $sectionUids = [];

    /** Element IDs the request must have matched. Empty means any. */
    public array $entryIds = [];

    /** Site UIDs the bar may appear on. Empty means every site the bar is enabled for. */
    public array $siteUids = [];

    public string $auth = self::AUTH_ANY;

    /** User group UIDs, when {@see $auth} is `members`. Empty means any signed-in user. */
    public array $userGroupUids = [];

    /**
     * Query-string conditions, each `['name' => string, 'value' => string]`. An empty value
     * tests only that the parameter is present, which is what a `?utm_source=…` rule usually
     * wants.
     */
    public array $queryParams = [];

    // ---------------------------------------------------------------- client-side

    /** Which device classes the bar shows on. Empty is treated as "all" rather than "none". */
    public array $devices = [self::DEVICE_DESKTOP, self::DEVICE_TABLET, self::DEVICE_MOBILE];

    /** Viewport width at or below which a visitor counts as being on a phone. */
    public int $mobileMaxWidth = 640;

    /** Viewport width at or below which a visitor counts as being on a tablet. */
    public int $tabletMaxWidth = 1024;

    public string $visitor = self::VISITOR_ANY;

    public string $referrer = self::REFERRER_ANY;

    /** Hostnames matched against `document.referrer` when {@see $referrer} is `domains`. */
    public array $referrerDomains = [];


    /**
     * Squares up the repeatable rows.
     *
     * The CP posts table rows including the blank one at the bottom, so every list here arrives
     * with an empty trailing entry that would otherwise become a rule matching everything.
     */
    public function normalize(): void
    {
        $this->uriRules = array_values(array_filter(array_map(function($rule) {
            $pattern = trim((string)($rule['pattern'] ?? ''));

            return $pattern === '' && ($rule['mode'] ?? '') === '' ? null : [
                'pattern' => $pattern,
                'mode' => ($rule['mode'] ?? 'include') === 'exclude' ? 'exclude' : 'include',
            ];
        }, $this->uriRules)));

        $this->queryParams = array_values(array_filter(array_map(function($param) {
            $name = trim((string)($param['name'] ?? ''));

            return $name === '' ? null : [
                'name' => $name,
                'value' => trim((string)($param['value'] ?? '')),
            ];
        }, $this->queryParams)));

        $this->referrerDomains = array_values(array_filter(array_map(
            fn($domain) => strtolower(trim((string)$domain, " \t\n\r\0\x0B/")),
            $this->referrerDomains,
        ), fn($domain) => $domain !== ''));

        $this->sectionUids = array_values(array_filter(array_map('strval', $this->sectionUids)));
        $this->siteUids = array_values(array_filter(array_map('strval', $this->siteUids)));
        $this->userGroupUids = array_values(array_filter(array_map('strval', $this->userGroupUids)));
        $this->entryIds = array_values(array_filter(array_map('intval', $this->entryIds)));

        $valid = [self::DEVICE_DESKTOP, self::DEVICE_TABLET, self::DEVICE_MOBILE];
        $this->devices = array_values(array_intersect($valid, array_map('strval', $this->devices)));

        if (!$this->devices) {
            $this->devices = $valid;
        }
    }

    protected function defineRules(): array
    {
        return [
            [['pages'], 'in', 'range' => [self::PAGES_ALL, self::PAGES_HOME, self::PAGES_URIS, self::PAGES_ELEMENTS]],
            [['auth'], 'in', 'range' => [self::AUTH_ANY, self::AUTH_GUESTS, self::AUTH_MEMBERS]],
            [['visitor'], 'in', 'range' => [self::VISITOR_ANY, self::VISITOR_FIRST, self::VISITOR_RETURNING]],
            [['referrer'], 'in', 'range' => [
                self::REFERRER_ANY,
                self::REFERRER_DIRECT,
                self::REFERRER_SEARCH,
                self::REFERRER_EXTERNAL,
                self::REFERRER_DOMAINS,
            ]],
            [['mobileMaxWidth', 'tabletMaxWidth'], 'integer', 'min' => 1],
            [['tabletMaxWidth'], 'compare', 'compareAttribute' => 'mobileMaxWidth', 'operator' => '>'],
            [['uriRules'], 'validateUriRules', 'skipOnEmpty' => false],
            [['referrerDomains'], 'validateReferrerDomains', 'skipOnEmpty' => false],
            [['pages'], 'validatePagesHaveRules', 'skipOnEmpty' => false],
        ];
    }

    /**
     * A regular expression that does not compile is worth catching here, because the alternative
     * is a warning on every front-end request and a bar that silently never appears.
     */
    public function validateUriRules(): void
    {
        foreach ($this->uriRules as $rule) {
            if (!str_starts_with($rule['pattern'], 're:')) {
                continue;
            }

            $pattern = self::compileRegex($rule['pattern']);

            if (@preg_match($pattern, '') === false) {
                $this->addError('uriRules', Craft::t('blaster', '“{pattern}” is not a valid regular expression.', [
                    'pattern' => $rule['pattern'],
                ]));
            }
        }
    }

    public function validateReferrerDomains(): void
    {
        if ($this->referrer === self::REFERRER_DOMAINS && !$this->referrerDomains) {
            $this->addError('referrerDomains', Craft::t('blaster', 'Add at least one domain, or choose a different referrer rule.'));
        }
    }

    /**
     * "Selected pages" with nothing selected would match everything, which is the opposite of
     * what the author picked, so it is refused rather than reinterpreted.
     */
    public function validatePagesHaveRules(): void
    {
        if ($this->pages === self::PAGES_URIS && !$this->uriRules) {
            $this->addError('uriRules', Craft::t('blaster', 'Add at least one URI rule, or show the bar on all pages.'));
        }

        if ($this->pages === self::PAGES_ELEMENTS && !$this->sectionUids && !$this->entryIds) {
            $this->addError('sectionUids', Craft::t('blaster', 'Choose at least one section or entry, or show the bar on all pages.'));
        }
    }

    /** Turns a stored `re:…` pattern into something `preg_match` will take. */
    public static function compileRegex(string $pattern): string
    {
        return '/' . str_replace('/', '\/', substr($pattern, 3)) . '/i';
    }

    public function forRuntime(): array
    {
        return [
            'devices' => $this->devices,
            'mobileMax' => $this->mobileMaxWidth,
            'tabletMax' => $this->tabletMaxWidth,
            'visitor' => $this->visitor,
            'referrer' => $this->referrer,
            'referrerDomains' => $this->referrerDomains,
        ];
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'pages' => $this->pages,
            'uriRules' => $this->uriRules,
            'sectionUids' => $this->sectionUids,
            'entryIds' => $this->entryIds,
            'siteUids' => $this->siteUids,
            'auth' => $this->auth,
            'userGroupUids' => $this->userGroupUids,
            'queryParams' => $this->queryParams,
            'devices' => $this->devices,
            'mobileMaxWidth' => $this->mobileMaxWidth,
            'tabletMaxWidth' => $this->tabletMaxWidth,
            'visitor' => $this->visitor,
            'referrer' => $this->referrer,
            'referrerDomains' => $this->referrerDomains,
        ];
    }
}
