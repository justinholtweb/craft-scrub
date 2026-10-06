# Release Notes for Scrub

## 5.0.1 - 2026-10-06

> {warning} Rules that search database tables now need **Rewrite non-element database tables** to
> save, edit, preview or run — **Manage saved rules** alone is no longer enough. Grant it to anyone
> who maintains those rules. Previews and runs from the control panel also leave out elements the
> person can't view, so a run started by an editor with limited access changes less than it did.

### Fixed

- The Replace screen threw `Cannot assign string to property ...Scope::$elementTypes of type array`
  on preview and on run. Craft's checkbox groups and multiselects post a hidden field under the
  bare name so the key is always present, so a control with nothing ticked arrives as `''` rather
  than as `[]`, and the typed `array` properties rejected it. Every list on the screen was
  affected — element types, sources, fields, sites, element IDs and targets — and because "leave
  every box unticked to search all of them" is the documented default, the ordinary path was the
  broken one. The empty-slot filtering that already existed ran after the assignment that threw.
- Saving a database table in settings could fail the same way. The form posts each table's columns
  and JSON columns as comma-separated text, and the typed `array` properties rejected it before the
  existing split ran. The text is now split first.

### Security

- A saved rule could rewrite raw database tables for somebody without **Rewrite non-element
  database tables**. Only the Replace screen's run checked that permission. Saving a rule only
  needed **Manage saved rules**, and running it from the rules screen didn't check, so the rule
  could be saved and then run, or left for its schedule to run as the site. Saving or running a
  rule that targets database tables now needs the permission, and so does editing one that already
  does, so the text of an admin's database rule can't be changed by someone who couldn't have
  written it.
- The preview showed matching snippets of content the viewer couldn't otherwise see: rows from
  database tables, without the database permission, and elements outside the sections and volumes
  they can view. A database preview now needs the permission. Elements the person can't view are
  left out of the preview, with a note saying how many, and out of the run as well, so a run
  changes exactly what its preview showed. A queued run carries who queued it, and fails rather
  than run as the site if that user is gone. Console runs and scheduled rules still act as the
  site.

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
