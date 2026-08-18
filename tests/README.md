# Tests

## Unit suite

```bash
composer test
```

Plain PHP — no Craft application, no database. It covers the part of Scrub that has to be exactly
right and can be checked in isolation:

- **`MatcherTest`** — how a find/replace definition compiles into a pattern, what it matches, what
  it deliberately leaves alone, how backreferences expand, and how capitalisation is carried across.
  Nearly every case is one that a naive `str_replace` gets wrong, which is the argument for the
  plugin existing.
- **`LiteralTest`** — the literal-substring extractor that lets a regular expression still use a SQL
  prefilter. Only one property really matters: whatever it returns must be contained in *every*
  subject the pattern matches. Returning too little is slow; returning something that isn't actually
  required means rows are silently missed, which would make the preview a lie. So each test is
  either "it found the run" or "it correctly gave up".

## Everything else

Anything that reads or writes an element needs a real site, and is exercised against the
`plugin-testing` harness instead. What that covers, and what to check when something changes:

- A scan finds matches in titles, plain text, rich text and table fields, and **not** in revisions
  or drafts unless asked — the single most important difference from Craft's own utility.
- CKEditor content is searched (it is an element-container field whose value is a *string*), while
  Matrix content is not searched from the owner (its value is an *array* holding the nested entries'
  own content, which would double-count). The nested entries are still searched as elements.
- A write updates the search index, and the run appears in the ledger.
- Undo restores every value, and **refuses** any value edited since the run, reporting it as a
  conflict rather than overwriting it.
- Changing a slug regenerates the URI. This is easy to break: Craft computes the URI in a
  *validator*, and Scrub saves with validation off, so `SlugTarget` calls `setElementUri()` itself.
- A real-time rule changes the rendered page and leaves the database alone, and does not touch class
  names, `href`s, attributes, `<script>` or `<style>`.
- An on-save rule corrects content as it is saved, and does not fire for revisions.
- The database target rewrites a configured table and refuses the tables Craft manages itself.
