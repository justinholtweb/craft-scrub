# Release Notes for Scrub

## Unreleased

Landed after the `5.0.0` tag, which has not been published. Fold it into 5.0.0 by recreating that
tag, or ship it as 5.0.1 — whichever, `composer.json`'s `version` has to match the tag's own tree
or Packagist skips the tag while the webhook still answers 202.

### Fixed

- The Replace screen threw `Cannot assign string to property ...Scope::$elementTypes of type array`
  on preview and on run. Craft's checkbox groups and multiselects post a hidden field under the
  bare name so the key is always present, so a control with nothing ticked arrives as `''` rather
  than as `[]`, and the typed `array` properties rejected it. Every list on the screen was
  affected — element types, sources, fields, sites, element IDs and targets — and because "leave
  every box unticked to search all of them" is the documented default, the ordinary path was the
  broken one. The empty-slot filtering that already existed ran after the assignment that threw.

## 5.0.0 - 2026-08-18

Initial release.

### Added

- Find and replace across element titles, custom field content, slugs, asset filenames and alt text,
  and configured non-element database tables.
- A preview that shares its code path with the run, showing every match in context with the old and
  new text side by side.
- Scoping by element type, section, volume, group, entry type, field, site and element ID, with
  drafts, revisions and trashed elements excluded by default.
- Plain, whole-word and regular expression matching, with capture groups, optional case sensitivity,
  and a case-preserving replacement that works word by word.
- Undo: every changed value recorded before and after, restored exactly, and refused where the value
  has been edited since the run.
- Safe writes through `saveElement()` — search index, caches, events and revisions all intact — plus
  an opt-in fast path that writes the content column directly and queues the re-index.
- Real-time rules that rewrite rendered output without changing anything stored, HTML-aware so that
  tags, attributes, class names, scripts and stylesheets are left alone.
- On-save rules, so new content arrives already corrected.
- Saved rules with handles, schedules and a `|scrub` Twig filter.
- A run ledger with history, per-run detail and one-click undo, plus `storage/logs/scrub.log`.
- Console commands `scrub/run/replace`, `scrub/run/rule`, `scrub/run/due`, `scrub/run/revert` and
  `scrub/run/rules`, all defaulting to a dry run.
- Guardrails: a per-run ceiling, protected fields and sources, a queue threshold, and separate
  permissions for previewing, replacing, undoing, managing rules and writing to database tables.
- `Targets::EVENT_REGISTER_TARGETS` for adding somewhere else to look, plus cancellable
  `beforeScrub` and `beforeWriteUnit` events.
- Craft's own Find and Replace utility is hidden by default, and can be brought back with one
  setting.
