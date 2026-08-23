<?php

namespace justinholtweb\blaster\twig;

use Craft;
use justinholtweb\blaster\elements\Bar;
use justinholtweb\blaster\elements\db\BarQuery;
use justinholtweb\blaster\Plugin;
use Twig\Markup;

/**
 * `craft.blaster` — for sites that want to place bars themselves.
 *
 * Calling {@see render()} stands automatic injection down for that request, so a template can
 * take over placement without anyone having to remember to turn the setting off. Nothing has to
 * be configured to move a bar out of the default spot; it is enough to say where it goes.
 */
class BlasterVariable
{
    /**
     * The markup for every bar this request matches.
     *
     * Safe to call more than once — the second call renders nothing, rather than a second copy.
     */
    public function render(): Markup
    {
        $renderer = Plugin::getInstance()->renderer;

        if ($renderer->hasRendered()) {
            return $renderer->markup('');
        }

        return $renderer->markup(
            $renderer->renderAll(Plugin::getInstance()->matcher->candidates()),
        );
    }

    /**
     * The bars this request matches, for templates that want to build their own markup.
     *
     * Note that these are *candidates*: the visitor-level conditions (device, referrer, first
     * visit, view caps, dismissals) are decided in the browser, so a template that renders these
     * itself is taking on that half of the job as well.
     *
     * @return Bar[]
     */
    public function bars(): array
    {
        return Plugin::getInstance()->matcher->candidates();
    }

    /** An element query, so templates can ask their own questions. */
    public function query(array $criteria = []): BarQuery
    {
        /** @var BarQuery $query */
        $query = Bar::find();

        if ($criteria) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    public function bar(string $handle): ?Bar
    {
        return Plugin::getInstance()->bars->getBarByHandle($handle);
    }

    /** @return array{views: int, clicks: int, dismissals: int} */
    public function stats(Bar|int $bar, ?int $siteId = null): array
    {
        return Plugin::getInstance()->stats->totalsForBar(
            $bar instanceof Bar ? (int)$bar->id : $bar,
            $siteId,
        );
    }
}
