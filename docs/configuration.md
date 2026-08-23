---
title: Configuration
slug: configuration
order: 20
summary: The seven plugin settings, what they default to, and the two defaults worth arguing with.
---

Blaster's own settings are deliberately few — almost everything that matters is decided per bar,
not site-wide. **Settings → Plugins → Blaster**, or `config/blaster.php`.

## Add bars automatically

**Default: on.**

Splices matching bars into front-end HTML responses, just before the last `</body>` in the page.
This is what makes Blaster work with no template change.

Turn it off if you want bars to appear only where you put them with
`{{ craft.blaster.render() }}`. You do not need to turn it off just to move a bar: calling the tag
stands injection down for that request on its own. The switch is for sites that want the tag to be
the *only* way a bar can ever appear.

Injection is decided on the response's **Content-Type**, not its format. Anything that is not HTML
— a JSON endpoint, an RSS feed, a `manifest.json` template — is left alone, as is every control
panel request, every action request, every AJAX request, and any `5xx` response. A `404` page is
a page like any other and does get a bar.

## Never add bars to

**Default: empty.**

URI patterns that never receive an injected bar, whatever a bar's own targeting says. The same
pattern syntax as a bar's URI rules:

| Pattern | Matches |
| --- | --- |
| `blog/*` | anything under `blog/` |
| `blog` | that page exactly |
| *(blank)* | the homepage |
| `*` | everything, homepage included |
| `re:^shop/[0-9]+$` | a regular expression |

This exists for HTML that is not really a page: an embed route, a printable view, an HTMX fragment
that gets swapped into a page which already has a bar on it. A fragment route is the common case —
it returns HTML, so it looks like a page, and a bar spliced into it lands in the middle of
somebody else's markup.

## Stacking order

**Default: `99999`.**

The `z-index` the bar container sits at. High enough to clear most sticky headers. Lower it if
Blaster is covering something that should be above it — a modal, a cookie manager, a support
widget.

## Count views and clicks

**Default: on.**

Records three integers per bar, per site, per day: views, clicks and dismissals. There is no event
row, no identifier, no address, no session and no cookie. See
[the FAQ](https://justinholt.com/plugins/craft-blaster/docs/faq) for what that does and does not
let you answer.

Turning it off stops the counting entirely. Bars still work; the numbers column just stays at
zero.

## Honour Do Not Track

**Default: off.**

Skips counting for visitors sending `DNT: 1`.

This is the first default worth arguing with, so here is the reasoning: Blaster's counters hold no
identifier, no address and no session, so they do not track anyone in the sense the header is
asking about — a bar's view count cannot be joined back to a person because nothing stored
describes one. Sites with a stricter policy, or a legal team that reads the header literally,
should switch it on and accept losing the corresponding share of their numbers.

## Keep counts for

**Default: 730 days.** `0` keeps everything.

Daily rows older than this are pruned during Craft's own garbage collection, and by
`php craft blaster/stats/prune`. Two years is enough to compare this year's Black Friday bar with
last year's, which is the only reason to keep a daily row that old.

## HTML Purifier config

**Default: Craft's default.**

The name of a `config/htmlpurifier/*.json` file used to clean bar messages on save. Bar wording is
rich text, so it goes through HTML Purifier exactly like a Redactor or CKEditor field does — the
setting is here so a bar's message can be allowed markup the rest of the site is not, or less.

Per-bar **custom CSS** is a separate matter and does not go through Purifier at all. Only a literal
`</style` is stripped from it, and that is stripped on the way *out* rather than on save, because
stored data can also arrive from a project config sync or a restored backup. Everything else in
custom CSS is passed through, which is why the permission to edit bars is a trusted one.

## config/blaster.php

Every setting can be set in a config file, which takes it out of the database and lets it vary per
environment:

```php
<?php

return [
    'autoInject' => true,
    'excludedUris' => ['embed/*', 're:^print/'],
    'zIndex' => 99999,
    'trackStats' => true,
    'respectDoNotTrack' => false,
    'statsRetentionDays' => 730,
    'purifierConfig' => 'Bars',
];
```

Note that `excludedUris` is a plain list of strings here, while the control panel posts it as
table rows. Both end up as the same list.

A setting overridden in the config file is shown in the control panel but cannot be edited there,
which is Craft's usual behaviour and not something Blaster does anything about.

## Where to go next

- [Usage](https://justinholt.com/plugins/craft-blaster/docs/usage) — targeting, scheduling, theming, Twig and the console
- [Troubleshooting](https://justinholt.com/plugins/craft-blaster/docs/troubleshooting) — when a bar does not appear
