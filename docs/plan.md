# Blaster — implementation plan

Notification bars for Craft CMS 5. A free plugin (single edition), in the spirit of WPFront
Notification Bar but built as a Craft plugin rather than a port of one.

## Shape of the thing

A **bar** is an element. Authors create bars in the CP, target them at pages/people/dates, and
Blaster injects the matching ones into every front-end HTML response. No template changes are
needed; a Twig tag exists for sites that want to place the markup themselves.

## The load-bearing split: server-side vs client-side targeting

Targeting conditions divide into two groups, and the division is the architecture:

- **Server-side** — URI, matched element/section, site, auth state, user group, query string,
  schedule. Evaluated in `services\Matcher` while building the response. A bar that fails these
  is never sent to the browser.
- **Client-side** — device width, referrer, first-vs-returning visitor, view caps, prior
  dismissal. Evaluated by the runtime from `localStorage` and `matchMedia`.

The reason is caching and privacy: anything that varies per *visitor* rather than per *request*
cannot be decided server-side without either breaking page caches or storing something about the
person. So the server emits every bar that could apply, hidden, with its client-side conditions
in a `data-blaster-config` attribute, and the runtime picks the winner. Nothing about a visitor
ever leaves their browser.

Consequence: **at most one bar shows per position**, and which one is a client-side decision.
The server sorts candidates by `priority` and the runtime takes the first eligible one per
position.

## Data model

- `elements\Bar` — localized, statused (`live` / `pending` / `expired` / `disabled`).
- `{{%blaster_bars}}` — what a bar *is*: `handle` (unique), `position`, `priority`, `version`,
  and four JSON config columns backed by typed models. JSON rather than columns because these
  are read whole, never queried, and a new knob should not need a migration.
- `{{%blaster_bar_content}}` — what a bar *says*, per site: `(id, siteId)` PK, `message`,
  `buttonLabel`, `buttonUrl`, `buttonNewWindow`, `buttonEnabled`.
- `{{%blaster_stats}}` — aggregate counters keyed `(barId, siteId, date)`. Counts only; no
  visitor identifiers, no IPs, nothing that could identify a person.

### Config models

| Model | Holds |
|---|---|
| `BarBehavior` | trigger (immediate/delay/scroll/exit-intent), animation, auto-close, dismissal + duration, reopen tab, view cap, sticky, push-page |
| `BarTargeting` | page mode + URI rules, sections, entries, sites, auth state, user groups, query params, devices + breakpoints, visitor mode, referrer mode |
| `BarSchedule` | start/end datetimes, days of week, daily time window |
| `BarTheme` | colours, height, font size, alignment, border, shadow, width, button style, custom CSS |

`version` is bumped on every save. The runtime keys its dismissal record on it, so editing a bar
re-shows it to people who had dismissed the previous wording — the alternative (a stale dismissal
suppressing a new announcement) is the failure nobody notices until the announcement matters.

## Services

- `bars` — CRUD, handle authority, duplication, priority ordering
- `matcher` — server-side eligibility for the current request; the only place that decides
- `renderer` — bar + content → markup, scoped inline CSS, and the client-side config payload
- `stats` — upsert counters, read rollups, prune

## Injection

Auto-injection filters the response body and splices the markup in before `</body>`, rather than
hooking `View::EVENT_END_BODY`, because that hook requires the site's templates to call
`{{ endBody() }}` and many do not. CSS and JS are inlined: a bar that arrives after paint has
already failed, and the runtime is small enough that a second request costs more than it saves.

`{{ craft.blaster.render() }}` marks the request as rendered, so auto-injection stands down.

## Build order

1. Scaffolding — composer, licence, icons, changelog
2. Config models + settings
3. Records + install migration
4. `Bar` element + query
5. Services: bars, matcher, renderer, stats
6. Controllers: CP `bars`, site `track`
7. CP templates: index, edit, settings; live preview over Ajax so the renderer stays the one
   source of markup
8. Runtime JS/CSS
9. Twig variable + extension
10. `Plugin.php` wiring, permissions, GC
11. Integration checks + harness wiring
