---
title: Usage
slug: usage
order: 30
summary: Targeting, scheduling, appearance, the numbers, Twig, multi-site and the console.
---

Everything about a bar is edited in one place — **Bars → the bar** — under five tabs: Content,
Display, Targeting, Schedule and Theme. This page walks through what each one decides.

## Content

**Message** is rich text and is **per-site**. On a multi-site install the same bar carries a
different wording per site, which is what lets one announcement be translated rather than
duplicated into one bar per language.

**Button** is optional: a label, a URL, and whether it opens in a new window. A button click is
counted; a link inside the message is not, so use the button for the thing you actually want
measured.

**Handle** is not just a name. It is the key the browser records a dismissal under, so renaming a
handle makes every visitor who had closed the bar see it again. That is usually what you want when
the message has changed, and never what you want when you are just tidying up.

## Display — when it appears and when it goes away

| Setting | Default | What it does |
| --- | --- | --- |
| Trigger | Immediately | Immediately, after a delay, after scrolling a percentage of the page, or on exit intent |
| Delay | 3s | Seconds to wait, for the delay trigger |
| Scroll | 25% | How far down the page, for the scroll trigger |
| Animation | Slide | Slide, fade, or none |
| Animation duration | 350ms | How long the slide or fade takes |
| Auto-close | 0 | Seconds on screen before it closes itself. `0` means it stays |
| Dismissible | on | Whether the bar has a close button at all |
| Dismissal lasts | 7 days | `0` means the current browsing session only |
| Reopen tab | off | Leave a small tab that brings a closed bar back |
| Max views | 0 | Stop showing after this many impressions to one visitor. `0` is no cap |
| Sticky | on | Stay pinned while the page scrolls |
| Push page down | on | Push the content down instead of sitting on top of it |

A dismissal that lasts `0` days is held in `sessionStorage`, so it does not outlive the tab.
Anything longer is held in `localStorage`.

A reopen tab with no close button is a control that can never be reached, so that combination is
refused at save rather than quietly ignored.

**Push page down** is what stops a pinned bar from covering the top of the page. It pads the body
by the bar's measured height and re-measures with a `ResizeObserver` whenever that height changes,
so a bar whose wording wraps to two lines on a phone still pushes the right distance. The measured
height is also published as `--blaster-offset-top` / `--blaster-offset-bottom` on the root element,
so a fixed site header of your own can offset itself by it. It applies to **sticky** bars — a bar
that scrolls away with the document is in the page already and has nothing to push.

## Targeting

Targeting is split between the server and the browser, and knowing which half a condition lives in
explains most of Blaster's behaviour.

**Decided on the server**, while the page is built. A bar that fails one of these is never sent to
the browser at all:

- **Pages** — everything, the homepage, URI patterns, or particular sections and entries
- **Sign-in state** — anyone, signed-out visitors only, or signed-in visitors only
- **User groups** — specific groups
- **Sites** — which of your sites the bar is allowed on
- **Query string** — the parameter must be present; give it a value and it must match

**Decided in the browser**, from `matchMedia` and `localStorage`:

- **Device** — desktop, tablet, mobile, by width. The two breakpoints are per bar (640 and 1024 by default)
- **Referrer** — any, direct, from a search engine, from any external site, or from named domains
- **Visitor** — anyone, first-time, or returning
- **View caps and prior dismissals**

The reason for the split is caching and privacy. Anything that varies per *visitor* rather than
per *request* cannot be decided on the server without either defeating page caching or keeping a
record of the person. So the server sends every bar that could apply, hidden, with its remaining
conditions attached, and the browser picks the winner.

Two consequences fall out of that, and they are not configurable:

- **At most one bar shows in each position.** Where several match, the highest **priority** wins
  the spot. Two stacked announcements are not twice the message.
- **Nothing about a visitor is stored server-side.** Dismissals and view counts live in that
  visitor's own browser and are never sent anywhere.

One caveat worth stating plainly: targeting by **user group** is a server-side condition that
nevertheless varies per visitor. A full-page static cache in front of Craft will serve whichever
version it cached first. If you run one, target by sign-in state rather than by group, or exclude
those routes from the cache.

### URI patterns

| Pattern | Matches |
| --- | --- |
| `blog/*` | anything under `blog/` |
| `blog` | that page exactly |
| *(blank)* | the homepage |
| `*` | everything, homepage included |
| `re:^shop/[0-9]+$` | a regular expression |

Each rule is either an inclusion or an exclusion, and **an exclusion always wins**, whatever order
the rules are written in. Rules read as "show it here, except there": `blog/*` followed by an
exclusion of `blog/drafts/*` has to exclude the drafts however the two happen to be arranged on
screen.

A bar with only exclusions shows everywhere they do not name.

## Schedule

Scheduling is off until you switch it on. Then:

- **Start and end dates** decide the bar's status — before the start it is *pending*, after the
  end it is *expired*, and neither appears on the site.
- **Days of the week** restricts it to particular weekdays.
- **A daily window** restricts it to particular hours, and **may run past midnight**: a window of
  22:00 to 02:00 is four hours across two days, not an empty range.

The daily window and the weekdays are read in the **site's** time zone, because that is the one
the author was thinking in when they typed "9am". The start and end dates are stored in UTC.

## Theme

Background, text, link, button and close-button colours; button corner radius; type size; minimum
height; left, centre or right alignment; full-width or contained with a maximum inner width;
border width and colour; and a drop shadow.

The editor shows a **live preview** rendered by the same code that serves the front end — one
method generates a bar's CSS, and both callers use it, so the preview cannot drift away from the
real thing.

### Custom CSS

Per-bar CSS is emitted verbatim inside the bar's own `<style>` element. `{selector}` is replaced
with that bar's selector:

```css
{selector} .blaster-bar__message strong { letter-spacing: .04em; }
{selector} .blaster-bar__button:hover { transform: translateY(-1px); }

@media (max-width: 640px) {
  {selector} .blaster-bar__message { font-size: 14px; }
}
```

Useful class names, all inside the bar element:

| Class | Part |
| --- | --- |
| `.blaster-bar` | the bar itself, plus `--top` or `--bottom` |
| `.blaster-bar__inner` | the content row |
| `.blaster-bar__message` | the wording |
| `.blaster-bar__button` | the call to action |
| `.blaster-bar__close` | the close button |
| `.blaster-bar__reopen` | the reopen tab |

Only a literal `</style` is stripped from custom CSS — that is an escape from the element the CSS
lives in, not styling. Everything else goes through, which is why editing bars is a trusted
permission.

## Twig

By default there is nothing to add to a template. If you want to place bars yourself:

```twig
{{ craft.blaster.render() }}
```

Calling that stands automatic injection down for that request, so a template can move the markup
without any setting being changed.

The rest of the API:

```twig
{% set bars = craft.blaster.bars() %}          {# bars matching this request #}
{% set bar = craft.blaster.bar('spring-sale') %}
{% set totals = craft.blaster.stats(bar) %}    {# { views, clicks, dismissals } #}
{% set query = craft.blaster.query({ position: 'top' }) %}
```

`craft.blaster.query()` returns an element query, so it takes the usual `status`, `site`,
`orderBy` and `limit` parameters alongside Blaster's own `position`, `handle` and `priority`.

## The numbers

Views, clicks, dismissals and a click-through rate, per bar, per site, per day, shown on the
element index and on the bar itself.

That is three integers per bar per day, not a row per event. Which means the numbers answer *how
many times*, never *how many people* — for a notification bar that is the right way round, and it
is the price of storing nothing about anybody.

## Multi-site

A bar exists on every site. Its **wording** is per-site; its colours, targeting, display and
schedule are shared. So there is one bar to keep in step rather than one per language.

Use the **Sites** targeting option to keep a bar off particular sites, and the element index's site
switcher to translate it.

## Console

```sh
php craft blaster/bars/list                 # every bar, with status, position and counts
php craft blaster/bars/list --site=de
php craft blaster/stats/prune               # drop counters past the retention setting
php craft blaster/stats/prune --days=90
```

Pruning also happens during Craft's own garbage collection, so the command is only needed for a
one-off.

## Where to go next

- [Troubleshooting](https://justinholt.com/plugins/craft-blaster/docs/troubleshooting) — when a bar does not appear
- [FAQ](https://justinholt.com/plugins/craft-blaster/docs/faq) — privacy, caching, and what happens if you remove it
