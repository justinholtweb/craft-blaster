<?php

namespace justinholtweb\blaster\models;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use DateTime;
use DateTimeZone;

/**
 * Everything {@see \justinholtweb\blaster\services\Matcher} is allowed to know about a request.
 *
 * A value object rather than a pile of arguments, so that matching can be exercised in a test
 * against a synthesised request — "does this bar show on `blog/*` to a signed-out visitor on a
 * Tuesday" is a question worth being able to ask without an HTTP request in the way.
 */
class RequestContext
{
    public function __construct(
        public readonly int $siteId,
        public readonly string $siteUid,

        /** Path with no leading or trailing slash. The homepage is the empty string. */
        public readonly string $uri,

        public readonly ?ElementInterface $element = null,
        public readonly ?User $user = null,

        /** @var array<string, string> */
        public readonly array $queryParams = [],

        /** Always UTC. The site's own zone is applied where the author's intent needs it. */
        public readonly ?DateTime $now = null,
    ) {
    }

    public static function fromRequest(): self
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $request = Craft::$app->getRequest();
        $matched = Craft::$app->getUrlManager()->getMatchedElement();

        return new self(
            siteId: $site->id,
            siteUid: $site->uid,
            uri: trim($request->getPathInfo(), '/'),
            element: $matched instanceof ElementInterface ? $matched : null,
            user: Craft::$app->getUser()->getIdentity(),
            queryParams: array_map(
                fn($value) => is_array($value) ? (string)reset($value) : (string)$value,
                $request->getQueryParams(),
            ),
            now: new DateTime('now', new DateTimeZone('UTC')),
        );
    }

    public function getNow(): DateTime
    {
        return $this->now ?? new DateTime('now', new DateTimeZone('UTC'));
    }

    public function isHomepage(): bool
    {
        return $this->uri === '' || $this->uri === '__home__';
    }
}
