---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and getting your first bar onto the site.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

That is the whole list. Blaster has no runtime dependencies beyond Craft's own, no build step, and
makes no outbound HTTP request anywhere — not at install, not on a page view, not ever.

Blaster is free. There are no editions, no licence key and no licensing code in the plugin.

## Install

```sh
composer require justinholtweb/craft-blaster
php craft plugin/install blaster
```

Or find **Blaster** in the Plugin Store and install it there.

Installing creates three tables — `blaster_bars`, `blaster_bar_content` and `blaster_stats` — and
adds a **Bars** item to the control panel navigation. No project config is written until you
change a setting.

## Your first bar

1. **Bars → New bar.**
2. Give it a name and a **handle**. The handle is what the browser remembers a dismissal under, so
   pick something you will not want to rename later — `spring-sale`, not `bar-1`.
3. Write the **message**. It is rich text, cleaned by HTML Purifier on save, and it is per-site: a
   multi-site install translates the wording without duplicating the bar.
4. Choose a **position** — top or bottom.
5. Save.

The bar is live from that moment, on every front-end page of the site, because a new bar targets
everything by default. Load the front end and it is there.

Nothing was added to a template to make that happen. Blaster splices matching bars into front-end
HTML responses just before the closing `</body>` tag.

## Statuses

A bar shows one of four statuses in the element index, and only the first of them appears on the
site:

| Status | Meaning |
| --- | --- |
| **Live** | Enabled, and either unscheduled or inside its scheduled window |
| **Pending** | Enabled, but its start date has not arrived |
| **Expired** | Enabled, but its end date has passed |
| **Disabled** | Switched off by hand |

Pending and expired are computed from the schedule's start and end dates, which are stored on the
bar's own row rather than inside its JSON configuration — that is what lets the element index sort
and filter on status without loading every bar.

## Permissions

Three permissions ship with the plugin, all under **Blaster** in the user group settings:

| Permission | Allows |
| --- | --- |
| View bars | See the Bars section |
| Create and edit bars | Add and change bars, including per-bar custom CSS |
| Delete bars | Remove bars |

**Create and edit bars** is a trusted permission. Custom CSS is written into every page a bar
appears on, so give it to the people you would give template access to.

## Where to go next

- [Configuration](https://justinholt.com/plugins/craft-blaster/docs/configuration) — the plugin settings, and what the defaults assume
- [Usage](https://justinholt.com/plugins/craft-blaster/docs/usage) — targeting, scheduling, theming, Twig and the console
- [Troubleshooting](https://justinholt.com/plugins/craft-blaster/docs/troubleshooting) — when a bar does not appear
