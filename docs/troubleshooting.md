---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: When a bar does not appear, when the wrong one does, when a dismissal sticks, and when the layout fights back.
---

## A bar is not appearing at all

Work down this list; it is roughly in order of how often each one is the answer.

**Is it live?** The element index shows *live*, *pending*, *expired* or *disabled*. Pending and
expired mean the schedule has an opinion — check the start and end dates. Only *live* bars are
sent to a page.

**Have you already dismissed it?** The most common false alarm. Your own browser remembers, and it
remembers per bar and per version. Open a private window, or clear the `blaster.state` key from
`localStorage`.

**Is the page an HTML page?** Injection is decided on the response's Content-Type. A template that
renders JSON, an RSS feed, or anything served as something other than HTML is deliberately left
alone. So are control panel requests, action requests, AJAX requests and `5xx` responses.

**Does the page have a `</body>`?** Bars are spliced in before the last closing body tag. A
template that does not emit one — a fragment route, a hand-rolled partial served directly — has
nowhere to put a bar.

**Is the URI excluded?** The **Never add bars to** setting overrides every bar's own targeting.

**Is injection switched off?** Check **Add bars automatically** in the settings. And note that a
template calling `{{ craft.blaster.render() }}` stands injection down for that request — if a
layout calls it somewhere you have forgotten, that is why nothing gets spliced.

**Is another bar winning?** At most one bar shows per position. Where several match, the highest
priority takes the spot and the rest stay hidden. Two bars both set to priority `0` resolve by
their internal order, which is not something to rely on — give them different priorities.

**Is the trigger waiting?** A bar set to *exit intent* never appears until the pointer leaves
towards the browser chrome, which on a touch device means effectively never. A bar set to *scroll*
needs the page to be long enough to scroll that far.

**Is a device rule excluding you?** Device targeting is by viewport width, evaluated in the
browser. A narrow desktop window is a tablet as far as the rules are concerned.

If it is still missing, look at the page source. If the bar's markup is in the HTML but not
visible, the decision was made in the browser, and the answer is in the list above — dismissal,
view cap, device, referrer, first-versus-returning, or the trigger. If the markup is not in the
HTML at all, the decision was made on the server, and it is targeting, schedule, status or
injection.

## The bar came back after I edited it

That is deliberate, and it is the single most surprising thing Blaster does.

Every save bumps the bar's version, and a visitor's dismissal is recorded against the version they
dismissed. So editing a bar re-shows it to everyone who had closed the previous wording. Editing a
bar is how you say "this is a new thing to say", and a stale dismissal quietly suppressing a new
announcement is the failure nobody notices until the announcement matters.

Changing the **handle** has the same effect, because the handle is the key the browser remembers
the bar under.

If you need to fix a typo without re-showing the bar to everyone, there is no switch for it. The
version bump is unconditional.

## The bar covers my site header

Turn on **Push page down**, which pads the body by the bar's measured height rather than letting
the bar sit on top of the page.

If your header is itself `position: fixed`, padding the body does not move it — a fixed element is
out of flow. Offset it yourself with the custom property Blaster publishes on the root element:

```css
.site-header { top: var(--blaster-offset-top, 0px); }
```

It updates whenever the bar's height changes, and goes to `0px` when the bar is dismissed.

## The bar is behind something, or on top of something

**Stacking order** in the settings is the `z-index` the bar container sits at, `99999` by default.
Lower it if Blaster is covering a modal or a cookie manager; raise it if something is covering the
bar.

If neither helps, the other element is probably in a different stacking context, and no `z-index`
on Blaster's side will reach it. That is a CSS problem in the page rather than a setting.

## Custom CSS is not doing anything

Custom CSS needs `{selector}` to be scoped to the bar:

```css
{selector} .blaster-bar__message { font-weight: 600; }
```

A bare `.blaster-bar__message { … }` is valid CSS and will be emitted, but it applies to every
bar, and it competes with Blaster's own generated rules on equal specificity — whichever comes
last wins, which is not a stable thing to depend on.

If your rule is there in the page source and still losing, it is losing on specificity. Add the
selector twice (`{selector}{selector} …`) before reaching for `!important`.

## The counts look too low, or are staying at zero

Check **Count views and clicks** is on, and whether **Honour Do Not Track** is on — the second one
will silently drop a meaningful share of a real audience.

Beyond that: an ad blocker that blocks the counting request will cost you the view. Blaster counts
by beacon from the browser, which is what makes it work behind a page cache, and a beacon is a
request like any other.

Note also that a **click** is a click on the bar's button. A link inside the message body is not
counted — put the thing you want measured in the button.

## Numbers are right, but "how many people" is wrong

It is not a bug. Blaster stores three integers per bar, per site, per day, with no identifier of
any kind, so the numbers answer *how many times* and cannot answer *how many people*. See
[the FAQ](https://justinholt.com/plugins/craft-blaster/docs/faq).

## A page cache is serving the wrong bar

Server-side targeting by **user group** varies per visitor, and a full-page static cache in front
of Craft will serve whichever version it cached first. Target by sign-in state instead, or exclude
those routes from the cache.

Everything decided in the browser — device, referrer, first-versus-returning, dismissals — is
unaffected by caching, which is exactly why it is decided there.

## Still stuck

Mail [justin@justinholt.com](mailto:justin@justinholt.com) or open an issue on the
[issue tracker](https://github.com/justinholtweb/craft-blaster/issues). Include the Craft and PHP
versions, whether the bar's markup appears in the page source, and what the bar's targeting says.

## Where to go next

- [Configuration](https://justinholt.com/plugins/craft-blaster/docs/configuration) — every setting and what it defaults to
- [FAQ](https://justinholt.com/plugins/craft-blaster/docs/faq) — privacy, caching, and what happens if you remove it
