# Release Notes for Blaster

## 5.0.0

Initial release.

### Added

- Notification bars as a first-class element type, with a control panel section, per-site
  content, and `live` / `pending` / `expired` / `disabled` statuses.
- Top or bottom placement, sticky or in-flow, pushing the page down or overlaying it.
- Triggers: immediately, after a delay, after scrolling a percentage of the page, or on exit
  intent.
- Slide and fade animations, configurable auto-close, a close button, and an optional reopen tab.
- Dismissal that persists for a configurable number of days, reset automatically whenever the bar
  is edited.
- Per-visitor view caps.
- Server-side targeting by URI pattern, section, specific entries, site, sign-in state, user
  group, and query string.
- Client-side targeting by device width, referrer, and first-versus-returning visit — decided in
  the browser so that page caches keep working and nothing about a visitor is stored server-side.
- Scheduling with start and end dates, days of the week, and a daily time window.
- A theme editor covering colours, height, type size, alignment, border, shadow, width and button
  styling, plus per-bar custom CSS, with a live preview rendered by the same code that serves the
  front end.
- Impression, click and dismissal counts, aggregated per bar, per site, per day. No visitor
  identifiers are recorded.
- Automatic injection into front-end HTML responses, or manual placement with
  `{{ craft.blaster.render() }}`.
- `blaster/stats/prune` and `blaster/bars/list` console commands.
