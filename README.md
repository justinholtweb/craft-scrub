# Scrub

Find and replace across a Craft site, with a preview you can trust and an undo you can rely on.

Craft ships a Find and Replace utility. It selects every row of `elements_sites` whose title or
content is `LIKE` your search — every element type, every site, drafts and revisions included — runs
`str_replace` over it, and writes the result back with raw SQL. There is no preview, no scope, no
case handling, no word boundaries, no regular expressions, no undo, and no record that it ever ran.
Because it bypasses the element save, your search index is left describing content that no longer
exists, and nothing tells you.

Every part of Scrub is an answer to one of those sentences.

## What it does

**Previews before it writes.** The preview and the run are the same method with one boolean between
them, so what you are shown is not an estimate of what would happen — it is the run, with the last
step skipped. Each match is shown in context, with the old text and the new text side by side.

**Stops where you tell it to.** Sections, volumes, groups, entry types, individual fields, specific
sites, an explicit list of IDs. Drafts, revisions and trashed elements are excluded until you ask
for them — rewriting a revision doesn't correct history, it falsifies it.

**Matches the way you meant.** Plain text, whole words only, or a regular expression with capture
groups. Case sensitivity is yours to choose, and a case-preserving replacement handles "widget",
"Widget" and "WIDGET" from one rule — including multi-word matches, so "Acme Ltd" becomes "Acme Inc"
rather than "acme inc".

**Undoes itself.** Every changed value is recorded before and after. Undo puts it back exactly — and
refuses to touch anything that has been edited since the run, reporting those instead of silently
overwriting somebody's work. A database backup can't do that; it throws away every edit made in
between, which is why nobody ever uses one.

**Writes properly.** Elements are saved, so the search index updates, caches invalidate, `afterSave`
fires in every plugin that's listening, and entries get a revision. There is a fast path that writes
the content column directly, for sites where the safe one would take hours — it queues the re-index
that Craft's own utility forgets.

**Rewrites output without touching the database.** Real-time rules apply as pages render. Nothing
stored changes, so turning the rule off is the undo. The replacement is HTML-aware: it changes text
a reader sees and never a tag, attribute, class name, script or stylesheet.

**Corrects new writing as it arrives.** A rule can run on save, so the house style holds for content
written next month as well as content written last year.

## Where it looks

| Target | What it covers |
| --- | --- |
| **Titles and field content** | Element titles and every custom field — entries, categories, tags, assets, users, globals. Plain text, rich text, tables, dropdowns; anything whose stored value contains text. |
| **Slugs and URLs** | Entry and category slugs. URIs are regenerated from the new slug, so links follow. |
| **Asset filenames and alt text** | Renames the file on disk and clears its transforms, through Craft's own rename path. Alt text is per-site. |
| **Other database tables** | Form messages, redirects, SEO metadata, system emails — the content that isn't in elements. Configured deliberately, table by table, with JSON columns decoded before searching and re-encoded after. |

Anything else can register itself through `Targets::EVENT_REGISTER_TARGETS` — see
[docs/EXTENDING.md](docs/EXTENDING.md).

Matrix and content-block fields aren't searched from the outside: their entries are elements in
their own right and are searched as themselves, which is why the counts add up.

## Real-time rules

A great many replacements aren't permanent renames. A brand changes name in six weeks and the
content team hasn't finished. Legal wants a phrase gone from the public site today, while the
wording is still being argued about. A product is called one thing internally and another thing
publicly, and always will be. Rewriting a hundred thousand rows to solve any of those is how you end
up needing the undo.

A real-time rule applies as the page renders. The database is untouched.

```
Stored:   <a href="/acme-ltd/about" class="acme-ltd-link">Acme Ltd</a>
Rendered: <a href="/acme-ltd/about" class="acme-ltd-link">Acme Inc</a>
```

The class name and the URL are left alone because they aren't text a reader sees. Turn that off and
the same rule rewrites both — which is why it is on by default, and why the setting says so.

Rules can be limited to URI patterns (`blog/*`), and never apply in the control panel. In templates,
`{{ entry.body|scrub }}` applies them to one value, and `{{ text|scrub('handle') }}` applies one
named rule.

## Guardrails

- Every run is previewed server-side before it writes, including from the console and from a saved
  rule. Not a setting — it's how the thing works.
- A ceiling on how many places one run may change, so a mis-scoped rule fails loudly at the preview
  rather than quietly across 80,000 entries.
- Fields and sources that no rule may ever touch, enforced on every run wherever it came from.
- Runs above a threshold go to the queue rather than timing out halfway through.
- Replacing is a separate permission from previewing. Undoing, managing rules and writing to
  non-element tables are separate again.
- Everything is recorded — in a run ledger, and in `storage/logs/scrub.log`.

## Console

Everything defaults to a dry run. `--dry-run=0` is the only way to change anything, and it has to be
typed.

```bash
# See what would change
craft scrub/run/replace --find="Acme Ltd" --replace="Acme Inc" --mode=word --sources=section:3

# Change it
craft scrub/run/replace --find="Acme Ltd" --replace="Acme Inc" --mode=word --dry-run=0

# Run a saved rule
craft scrub/run/rule acme-rename --dry-run=0

# Scheduled rules that are due — belongs in cron
craft scrub/run/due --dry-run=0

# Undo
craft scrub/run/revert 42
```

## Editions

**Lite** — ad-hoc replacements, plain and whole-word matching, case sensitivity, full scoping,
titles, field content and slugs, preview, undo, run history, permissions, console.

**Pro** — regular expressions with capture groups, case-preserving replacement, saved rules,
real-time output rules, on-save rules, scheduled rules, asset filename and alt text, non-element
database tables, and the extensibility events.

## Requirements

Craft CMS 5.3+, PHP 8.2+.

## Installation

```bash
composer require justinholtweb/craft-scrub
php craft plugin/install scrub
```

Craft's own Find and Replace utility is hidden once Scrub is installed — two find-and-replace tools
side by side, one of which rewrites revisions and leaves the search index stale, is worse than
either on its own. It is hidden, never removed; one setting brings it straight back.
