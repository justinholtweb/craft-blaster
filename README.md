<p align="center"><img src="src/icon.svg" width="120" alt="Blaster"></p>

<h1 align="center">Blaster</h1>

<p align="center">Notification bars for Craft CMS 5 — announce, promote and warn, without touching a template.</p>

---

Blaster puts a bar at the top or bottom of your site: a sale, a shipping cut-off, a maintenance
window, a cookie notice, a "we've moved" announcement. You write it in the control panel, say who
should see it and when, and it appears. No template changes, no build step, no dependencies.

It is **free**, in one edition, with everything switched on.

## Requirements

Craft CMS 5.3+ and PHP 8.2+.

## Installation

From the Plugin Store, or:

```sh
composer require justinholtweb/craft-blaster
php craft plugin/install blaster
```

## What a bar can do

**Placement** — top or bottom; pinned while scrolling or sitting in the page; pushing the content
down or overlaying it.

**Appearance** — background, text, link, button and close-button colours; type size; minimum
height; alignment; full-width or contained; border; drop shadow; and per-bar custom CSS with a
`{selector}` token so a rule can be scoped to that bar. A live preview in the editor shows the
result, rendered by the same code that serves the front end.

**Triggers** — straight away, after a delay, after the visitor scrolls a percentage of the page,
or on exit intent.

**Going away** — a close button, an auto-close timer, a dismissal that lasts a configurable
number of days, and an optional tab that lets a visitor bring a closed bar back.

**Targeting** — every page, the homepage, URI patterns, particular sections or entries; signed-in
or signed-out; specific user groups; specific sites; query-string conditions; device width;
referrer; and first-versus-returning visitors.

**Scheduling** — start and end dates, days of the week, and a daily time window (which may run
past midnight), all read in the site's own time zone.

**Numbers** — views, clicks, dismissals and click-through rate, per bar, per site, per day.

## Where the bars come from

By default Blaster adds matching bars to every front-end HTML page automatically, just before
`</body>`. Nothing to add to a template.

To place them yourself:

```twig
{{ craft.blaster.render() }}
```

Calling that tag stands automatic injection down for that request, so you can move the markup
without changing a setting. To stop bars appearing anywhere except where you put them, turn off
**Add bars automatically** in the settings.

Other things available in Twig:

```twig
{% set bars = craft.blaster.bars() %}          {# bars matching this request #}
{% set bar = craft.blaster.bar('spring-sale') %}
{% set totals = craft.blaster.stats(bar) %}    {# { views, clicks, dismissals } #}
{% set query = craft.blaster.query({ position: 'top' }) %}
```

## The one design decision worth knowing about

Targeting is split in two, and the split explains most of how Blaster behaves.

**Server-side** — the URI, the matched entry, the site, whether someone is signed in and to which
group, the query string, the schedule. Decided while the page is built. A bar that fails these is
never sent to the browser at all.

**Client-side** — device width, referrer, first-versus-returning visit, view caps, and whether
this visitor has already closed the bar. Decided in the browser, from `localStorage` and
`matchMedia`.

The reason is caching and privacy. Anything that varies per *visitor* rather than per *request*
cannot be decided on the server without either defeating page caching or keeping a record of the
person. So the server sends every bar that could apply, hidden, and the browser picks the winner.

Two consequences:

- **At most one bar shows in each position.** Where several match, the one with the highest
  **priority** wins the spot. Two stacked announcements are not twice the message.
- **Nothing about a visitor leaves their browser.** Which is also why the counters are what they
  are — see below.

Note that server-side targeting by **user group** does vary per visitor, and a full-page static
cache in front of Craft will serve whichever version it cached first. If you run one, target by
sign-in state rather than by group, or exclude the cached routes.

## Editing a bar brings it back

Every save bumps the bar's version, and a visitor's dismissal is recorded against that version.
So editing a bar re-shows it to the people who had closed the previous wording.

This is deliberate. Editing a bar is how you say "this is a new thing to say", and a stale
dismissal quietly suppressing a new announcement is the failure nobody notices until the
announcement matters.

Changing a bar's **handle** has the same effect, because the handle is the key the browser
remembers it under.

## What Blaster records about visitors

Three integers per bar, per site, per day: views, clicks, dismissals.

There is no row per event, no identifier, no IP address, no session and no cookie. Nothing stored
can be joined back to a person, because nothing stored describes one. Dismissal state and view
counts live in the visitor's own `localStorage` and are never sent anywhere.

The trade is that the numbers answer "how many times", never "how many people". For a
notification bar that is the right way round.

If your policy is stricter, **Honour Do Not Track** in the settings skips counting for visitors
sending `DNT: 1`, and **Count views and clicks** turns the whole thing off.

## Settings

| Setting | Default | What it does |
|---|---|---|
| Add bars automatically | on | Splice matching bars into front-end HTML pages |
| Never add bars to | — | URI patterns that never receive a bar, whatever a bar targets |
| Stacking order | `99999` | The `z-index` bars sit at |
| Count views and clicks | on | Record the three counters |
| Honour Do Not Track | off | Skip counting for `DNT: 1` |
| Keep counts for | 730 days | Daily rows older than this are pruned |
| HTML Purifier config | Default | Which `config/htmlpurifier/*.json` cleans bar messages |

Settings can also be set in `config/blaster.php`.

## URI patterns

Used by a bar's URI rules and by the global exclusion list.

- `blog/*` — anything under `blog/`
- `blog` — that page exactly
- *(blank)* — the homepage
- `*` — everything, homepage included
- `re:^shop/[0-9]+$` — a regular expression

An **exclusion always wins**, whatever order the rules are written in — rules read as "show it
here, except there", and `blog/*` followed by an exclusion of `blog/drafts/*` has to exclude the
drafts however the two are arranged on screen.

If a bar has only exclusions, everything they do not name is fair game.

## Permissions

| Permission | Allows |
|---|---|
| View bars | See the Bars section |
| Create and edit bars | Add and change bars, including custom CSS |
| Delete bars | Remove bars |

Custom CSS is written into the page, so **Create and edit bars** is a trusted permission — give it
to the people you would give template access to.

## Console commands

```sh
php craft blaster/bars/list                 # every bar, with status, position and counts
php craft blaster/bars/list --site=de
php craft blaster/stats/prune               # drop counters past the retention setting
php craft blaster/stats/prune --days=90
```

Pruning also happens during Craft's own garbage collection, so the command is only needed for a
one-off.

## Multi-site

A bar exists on every site. Its **wording** is per-site — message, button label, button link —
while its colours, targeting and schedule are shared, so there is one bar to keep in step rather
than one per language. Use the **Sites** targeting option to keep a bar off particular sites, and
the element index's site switcher to translate it.

## Documentation

Full documentation is at
[justinholt.com/plugins/craft-blaster/docs](https://justinholt.com/plugins/craft-blaster/docs) —
installation, every setting, targeting and scheduling, troubleshooting and an FAQ.

## Licence

The Craft License. See `LICENSE.md`. Blaster is free: no editions, no licence key, and no
licensing code in the plugin.
