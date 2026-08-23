# Blaster — Craft CMS 5 Plugin

## Project Overview

Blaster puts notification bars on a Craft site — announcements, promotions, warnings — targeted,
scheduled, and injected into every front-end page without a template change. Distributed as
`justinholtweb/craft-blaster`. **Free, single edition**, everything switched on. In the spirit of
WPFront Notification Bar, but built as a Craft plugin rather than ported from one.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step and no runtime dependencies. The front-end runtime is a plain IIFE in
  `src/resources/`, inlined into the page; the CP editor is a classic script.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\blaster`
- Package: `justinholtweb/craft-blaster`
- Handle: `blaster`

### The load-bearing idea: targeting is split in two

Anything that varies per **request** (URI, matched element, site, sign-in state, user group, query
string, schedule) is decided server-side in `services\Matcher`. Anything that varies per
**visitor** (device width, referrer, first-vs-returning, view caps, prior dismissals) is decided
in the browser by `src/resources/runtime.js`.

The reason is caching and privacy: deciding visitor-level conditions on the server means either
defeating page caches or keeping a record of the person. So the server emits *every candidate*,
hidden, with its client-side conditions in `data-blaster-config`, and the runtime picks the
winner.

Two consequences that fall out of this and are not negotiable:

- **At most one bar per position**, chosen client-side, server-sorted by `priority`.
- Nothing about a visitor is stored server-side. `{{%blaster_stats}}` holds three integers per
  bar, per site, per day — no event rows, no identifiers.

### Version-keyed dismissal

`blaster_bars.version` is bumped on every save (in `Bars::saveBar()`), and the runtime records a
dismissal against it. Editing a bar therefore re-shows it to people who dismissed the previous
wording. A stale dismissal suppressing a new announcement is the failure nobody notices until the
announcement matters.

When the runtime sees a new version it must clear **the stored dismissal as well as the view
count** — clearing only the count lets the bar through exactly once and then re-suppresses it,
which reads as "the edit worked, then stopped working".

### Data model

- `elements\Bar` — localized, statused (`live` / `pending` / `expired` / `disabled`).
- `{{%blaster_bars}}` — `handle` (unique-ish, see trash below), `position`, `priority`, `version`,
  four JSON config columns, plus **denormalised `startDate`/`endDate`** so `BarQuery` can answer
  status in SQL. A date inside a JSON column cannot be indexed.
- `{{%blaster_bar_content}}` — `(id, siteId)` PK. The wording is translated; the configuration is
  not.
- `{{%blaster_stats}}` — `(barId, siteId, date)` unique; upserted with `views = views + 1`.

### Config models

`BarDisplay`, `BarTargeting`, `BarSchedule`, `BarTheme`, `BarContent` all extend `ConfigModel`,
whose `applyConfig()` casts posted strings against each property's **declared reflection type**
and leaves the default in place when a value cannot be read. `BarTheme::toCss()` is the single
source of a bar's CSS — the front end and the CP live preview both call it.

### Services

- `bars` — CRUD, handle authority, version bumping
- `matcher` — server-side eligibility; the only place that decides
- `renderer` — bar → markup, scoped CSS, runtime payload; also the CP preview
- `stats` — counter upserts, rollups, pruning, orphan collection

### Injection

`Response::EVENT_AFTER_PREPARE` rewrites the prepared body, splicing before the **last**
`</body>`. Not `View::EVENT_END_BODY`, which only fires for templates that call `{{ endBody() }}`
— plenty of real sites do not, and a plugin whose headline feature silently does nothing on those
sites is worse than one that asks for a template change.

## Traps found while building this

- **`yii\base\Component::getBehavior(string $name)` already exists.** An element accessor named
  `getBehavior()` with no argument is a fatal error at autoload time, not at the call site. This
  is why the model is `BarDisplay` and the accessor is `getDisplay()`. (Same family as the
  `Component::load()` collision in `[[craft-plugin-gotchas]]`.)
- **Craft does not render front-end templates as `Response::FORMAT_HTML`.** It uses its own
  `TemplateResponseFormatter::FORMAT` (`'template'`), so a `format !== FORMAT_HTML` guard matches
  *nothing* and the plugin silently never works. Test the **Content-Type header** instead — which
  is also correct for `feed.rss.twig` and `manifest.json.twig`, template responses that must be
  left alone.
- **`sendContentLengthHeader` stamps `content-length` during `prepare()`** — before
  `EVENT_AFTER_PREPARE`. Lengthening the body without restamping truncates the page at exactly
  the byte the bar was added at, which looks like a broken template rather than a broken header.
- **Craft's colour field posts hex without the leading `#`** (`1B8EF2`, not `#1B8EF2`). Every
  colour saved through the CP fails a `/^#[0-9a-f]{6}$/` rule and reverts to the default — and
  because Blaster's default *is* `#1B8EF2`, the bug is invisible until somebody picks a different
  colour. Normalised in `BarTheme::applyConfig()`.
- **A typed `int` property assigned `''` is a `TypeError`, not a zero.** CP number fields post
  `''` when cleared, so an author emptying a field would otherwise fatal the save. `ConfigModel`
  casts by reflection type and keeps the default when a value cannot be read.
- **Craft's date and time fields post arrays** (`['date' => …, 'time' => …, 'timezone' => …]`),
  which a generic caster will not put into a `?string`. `BarSchedule::applyConfig()` flattens them
  first.
- **Craft's lightswitch writes to a hidden input from JavaScript**, and setting `.value` in script
  fires no native `change` event — a `change` listener on the form never hears a switch being
  toggled. Listen for clicks on `.lightswitch` as well.
- **The CP editor wrapper must be a `<div>`, not a `<form>`** (nested forms corrupt the page
  form), so `new FormData(wrapper)` throws — build it from `wrapper.closest('form')`, and
  `delete` the `action` and `redirect` entries or the preview posts itself into the save action
  and follows the redirect.
- **Custom CSS only needs one thing stripped: a literal `</style`.** That is not styling, it is an
  escape from the element the CSS lives in. Colours and numbers are sanitised **on the way out**,
  not the way in, because stored data can arrive from a project-config sync or a restored backup.
- A **soft-deleted bar keeps its row**, so the handle index is not unique and `handleIsTaken()`
  asks an element query. `afterDelete()` parks the handle as `handle--trashed-<id>`;
  `afterRestore()` claims it back, or a variation.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## The icon

`src/icon.svg` is a **ray gun** — a white sci-fi blaster on a rounded tile in `#C62D25`, drawn by
Justin and dropped in as a finished file. It replaced a hand-built megaphone with three blast
arcs. The pun is better and the mark is more distinctive at 32px, which is where an icon is
actually judged.

It is **traced artwork, not hand-written geometry**: a `<defs>` of `p0`–`p12` used twice, once
stroked at `10pt` and once filled. Don't tidy that into single paths — the stroke pass is where
half the weight comes from, and the fills alone are noticeably lighter (which is exactly why the
mask uses them alone; see below).

Two things had to be changed about the file as delivered, both packaging rather than artwork:

- **`p0` painted an opaque `#fcfefb` field around the tile** — the whole 284×264 canvas with the
  tile knocked out of it. On this family's dark marketing pages and on the promo slides that is a
  white slab with the icon sitting in it. Dropping the outer `M 0 0 L 284 0 …` rectangle leaves
  `p0` as just the tile, and the surround goes transparent. The gun still reads white because
  `p0` shows through the holes in the red `p1`.
- **The frame was 284×264 and the tile was not centred in it**, so anything square letterboxed it.
  Cropped to `viewBox="16.4 15.9 238.2 238.2"` — the tile's own bounds plus the 5pt the stroke
  puts outside them, square, centred on the tile.

`src/icon-mask.svg` is the gun on its own, black on transparent, for Craft's control-panel nav. It
lifts the paths the icon fills in `#fcfefb` (`p2`, `p3`, `p4`, `p5`, `p8`, `p10`, `p11`) and
**leaves the stroke off**. With the icon's `10pt` stroke carried over, the barrel rings, muzzle and
trigger guard weld into one lump that reads as a hair dryer at 18px; the fills alone keep the
segments apart. The red detail paths are not included either — they sit inside the body path and
already render as holes in it, which is what keeps the ridges open.

Three copies exist and all three have to move together: `src/icon.svg`, `promos/assets/icon.svg`,
and `justinholt/web/images/plugins/blaster.svg` — followed by `ddev craft index-assets/all`, or the
page import relates no logo at all and "succeeds".

**The accent moved to `#C62D25` with the icon, but only for the brand.** The marketing page's
`accentColor`, the promo deck palette and the watermark all follow it. `BarTheme`'s shipped
defaults — `background` `#1B8EF2`, `buttonBackground` and `borderColor` `#0E2A47` — deliberately
did **not** move: they are the default colours of a notification bar on somebody's site, not
Blaster's branding, and a bar that defaults to alarm red says something the author did not choose.
Three integration checks pin `#1B8EF2` as that default; if it is ever changed, they change with it.

## Plugin Store promos

`promos/` renders the seven 1920×1080 marketing images for the Plugin Store listing:

```sh
./promos/build.sh          # all slides
./promos/build.sh "2 5"    # just those two
```

They live **in this repo**, not in a website repo — plugin marketing sites are pages inside the
justinholt.com install now, and the promos advertise the plugin rather than the page.

Three things learned building the deck:

- **The watermark is an outline, and the icon's tile is stripped.** Two separate corrections, the
  same pair every deck in this family needs. The tile goes because at slide scale its edge is a
  hard rectangle laid over the artwork; the fill goes because a filled ray gun at 5% opacity is a
  soft grey mass in which the barrel ridges and the trigger guard — the things that make it read
  as a ray gun — all disappear. Same family of trap as Abacus's frame bars and Bed's slab. Stroked,
  the same paths are line art and every segment reads.
- **The watermark had to be resized and moved when the icon changed.** The megaphone sat at 1180px
  from top -12% / left -13%. The gun is a wide, short shape, and that crop cut the grip off the
  left edge and left the barrel ridges floating — legible as *something*, not as a ray gun. 900px
  from top 2% / left -4% puts the whole mark on the slide.
- **`.points li` is a flex container.** An inline `<span class="mono">` or `<em>` left loose in the
  text becomes a *sibling flex item* and picks up the 16px gap on both sides. Every point keeps its
  text in one `<span class="t">`.
- **A dashed 1px border does not read at 1920.** Slide 3 marks the browser-side targeting rules
  with one and they are indistinguishable from the plain rows, so the copy points at the
  `SERVER` / `BROWSER` labels and the filled backgrounds instead. The same distinction survives on
  slide 6 only because those chips are also coloured.

One line per bullet, about 58 characters at 25px: the copy column is 780px and the panel starts at
`left: 900px`, so a wrapped bullet both crowds the panel and pushes the last point at the footer
lockup — and nothing clips, the text simply lands on top.

Blaster is free, so unlike the paid plugins in the family there is no price on the cover badge and
no figure anywhere in the deck for a pricing change to strand.

## The marketing site

`justinholt.com/plugins/craft-blaster`, a page inside that install — not a standalone project. The
procedure and its traps live in the **`plugin-marketing-site` skill** in that repo; invoke it
rather than working from memory.

`docs/*.md` is the source of truth for the site's documentation, synced by
`pluginsite/docs/sync craft-blaster`. Front matter is required — a file without it is skipped,
which is how `docs/plan.md` stays off the site.

**Cross-document links in `docs/` must be absolute.** The site's docs sidebar links to
trailing-slash URLs, so a bare relative `](configuration)` resolves *under* the current page and
404s — even though the non-slashed URL answers fine when tested by hand. Craft's markdown gives
headings no `id` either, so a `#fragment` link is dead too; name the section in bold instead.

The page seed is `justinholt/scripts/seed/plugin-pages/craft-blaster.json`. Everything the page
says about behaviour has to stay true of `src/models/Settings.php` and the four config models —
the defaults are quoted on the marketing page, in `docs/configuration.md` and in `docs/usage.md`,
and nothing checks that they agree.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-blaster/tests/integration/checks.php    # 78 checks
ddev exec bash -c 'find /var/www/craft-blaster/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The checks are idempotent and self-cleaning. `ddev exec php craft clear-caches/cp-resources`
after editing anything under `src/web/assets/*/dist`, or Craft keeps serving the published copy.
Editing `src/resources/*` needs no cache clear — those are read at render time, not published.

## Coding conventions

- `Craft::t('blaster', '…')` for user-facing strings
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
