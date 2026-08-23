---
title: FAQ
slug: faq
order: 50
summary: Cost, privacy, GDPR, caching, WPFront, what it stores, and what happens to your pages if you remove it.
---

## Is Blaster really free?

Yes. One edition, everything switched on, no licence key and no licensing code in the plugin.
Nothing is held back for a paid tier, because there is no paid tier.

## What does it store about visitors?

Three integers per bar, per site, per day: views, clicks and dismissals.

That is the whole of it. No row per event, no identifier, no IP address, no session, no cookie,
no fingerprint. Nothing stored can be joined back to a person because nothing stored describes
one.

A visitor's own state — which bars they have closed, how many times they have seen one, whether
they have been here before — lives in that browser's `localStorage` and is never sent anywhere.

## Does that mean I need a cookie banner for it?

Blaster sets no cookies. It writes to `localStorage`, which is the visitor's own browser and never
leaves it, and the values it writes are a bar handle, a version number and a count.

Whether your jurisdiction treats that as requiring consent is a question for whoever advises you,
not for a plugin's documentation. What is safe to say is that there is no identifier to consent
to sharing, and no third party for it to be shared with.

If you would rather be conservative, **Honour Do Not Track** in the settings skips counting for
visitors sending `DNT: 1`, and turning off **Count views and clicks** stops the counting
altogether. Neither affects whether bars work.

## Does it phone home?

No. There are no outbound HTTP requests anywhere in the plugin — not at install, not on a render,
not ever. The only request the runtime makes at all is the counting beacon, and that goes to your
own site.

## Why can I only show one bar per position?

Because the bar that a visitor sees is chosen in their browser, from the candidates the server
sent, and two stacked announcements are not twice the message — they are half the attention each.

Where several bars match, the one with the highest **priority** takes the position. If you need
two things said at once, say them in one bar.

## Will it slow my site down?

Not measurably. On a request with no matching bars, the cost is one indexed element query and a
pattern match against the exclusion list. On a request with one, add the bar's markup and a small
inline stylesheet.

The runtime is a plain IIFE inlined into the page — no external script, no request, no framework.
There is no build step and no dependency to load.

## Does it work with Blitz, Varnish or Cloudflare?

Yes, and this is most of the reason the design is what it is. Everything that varies per *visitor*
is decided in the browser precisely so that a cached page can serve everybody.

The one thing to watch is server-side targeting by **user group**, which varies per visitor
despite being decided on the server. A full-page cache will serve whichever version it cached
first. Target by sign-in state instead, or exclude those routes from the cache.

## Do I have to change my templates?

No. Blaster splices matching bars into front-end HTML responses just before the last `</body>`.

It hooks the response rather than the template layer on purpose: hooking `{{ endBody() }}` would
only work for templates that actually call it, and plenty of real sites do not. A plugin whose
headline feature silently does nothing on those sites is worse than one that asks for a template
change.

If you would rather place bars yourself, `{{ craft.blaster.render() }}` puts them exactly where
you call it and stands injection down for that request.

## Why did my bar come back after I edited it?

Because that is what editing a bar means. Every save bumps the bar's version, and a dismissal is
recorded against the version it dismissed — so a change re-shows the bar to the people who had
closed the old wording.

A stale dismissal quietly suppressing a new announcement is the failure nobody notices until the
announcement matters. There is no switch to turn this off.

## Can I count how many *people* saw a bar?

No, and that is a deliberate limit rather than a missing feature. Counting people means keeping
something that identifies one, and Blaster keeps nothing. The numbers answer *how many times*.

For a notification bar that is the right way round: what you actually want to know is whether the
message is being seen and acted on, and views, clicks and a click-through rate answer that.

## How is this different from WPFront Notification Bar?

Blaster does the same job and was inspired by it, but it is not a port — no WordPress code, no
WordPress assumptions. It is built as a Craft plugin: bars are elements, so they get the element
index, statuses, per-site content, permissions and element queries for free.

The features WPFront charges for — scheduling, multiple bars, targeting, statistics — are all
here at no cost.

## Does it work on a multi-site install?

Yes. A bar exists on every site, its **wording** is translatable per site, and its configuration
is shared, so one announcement is one bar rather than one per language. The **Sites** targeting
option keeps a bar off the sites it does not belong on.

## Is there GraphQL support?

Not yet. Bars are queryable from Twig with `craft.blaster.bars()`, `craft.blaster.bar()` and
`craft.blaster.query()`, and from the console with `blaster/bars/list`.

## What happens if I uninstall it?

Uninstalling drops the three tables and the bar elements, and your pages go back to exactly what
they were — the bars were never in a template, so there is nothing left behind to clean up.

Nothing else in your install is touched.

## Where do I report a bug?

Mail [justin@justinholt.com](mailto:justin@justinholt.com), or open an issue on the
[issue tracker](https://github.com/justinholtweb/craft-blaster/issues). Include the Craft and PHP
versions and what the bar's targeting says — and have a look at
[Troubleshooting](https://justinholt.com/plugins/craft-blaster/docs/troubleshooting) first.
