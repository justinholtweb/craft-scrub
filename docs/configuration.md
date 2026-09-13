---
title: Configuration
slug: configuration
order: 70
summary: Every setting, what it defaults to, and why it defaults to that.
---

# Configuration

Settings live at **Scrub → Settings**, or in `config/scrub.php`, which wins over the
control panel and is the right place for anything that should differ between staging and
production.

```php
<?php

return [
    'writeMode' => 'safe',
    'createRevisions' => true,
    'recordUndo' => true,
    'undoRetentionDays' => 90,

    'maxUnitsPerRun' => 5000,
    'maxUnitsInPreview' => 200,
    'maxHitsPerUnit' => 10,
    'queueThreshold' => 100,

    'protectedFields' => [],
    'protectedSources' => [],

    'realtimeEnabled' => true,
    'replaceCoreUtility' => true,

    'defaultMode' => 'plain',
    'defaultCaseSensitive' => true,

    'tables' => [],
    'logLevel' => 'info',
];
```

## How it writes

**`writeMode`** — `safe` or `fast`. Safe saves the element: search index, caches,
`afterSave`, revisions. Fast writes the content column directly and queues a re-index,
and nothing else happens. Fast exists because on a 200,000-element site the safe path is
measured in hours. It is not the default because on every other site the difference is
seconds and the cost is invisible breakage.

**`createRevisions`** — whether changed entries get a revision. On. Scrub's own undo is
more precise, but a revision is how an *editor* sees, in the interface they already use,
that their page changed and what it changed from. Turn it off for a run across tens of
thousands of entries, where the revisions cost more than they're worth.

**`recordUndo`** — whether every change is stored so the run can be undone. On. One row
per changed value.

**`undoRetentionDays`** — days to keep undo records. 0 keeps them forever.

## Guardrails

**`maxUnitsPerRun`** — the most places one run may change. 0 removes the ceiling. This
is here to make a mis-scoped rule fail loudly at the preview rather than quietly across
eighty thousand entries.

**`maxUnitsInPreview`** — how many places the preview lists before it stops listing and
starts counting.

**`maxHitsPerUnit`** — how many individual matches are shown per place.

**`queueThreshold`** — above this many matching places, the run goes to the queue instead
of running in the request. 0 always runs in the request.

**`protectedFields`** — field handles no rule may ever change, whatever the form says.

**`protectedSources`** — source keys (`section:3`, `volume:1`) that no rule may ever
change.

Both are enforced by the scanner, so the console command and the queue job obey them too.

## Real-time

**`realtimeEnabled`** — the master switch for render-time replacement, separate from the
per-rule setting. A site with a performance problem can turn the whole feature off in one
place, and staging can differ from production through this file.

## The Replace screen

**`replaceCoreUtility`** — whether Craft's own Find and Replace utility is hidden. On.
It is only hidden, never removed.

**`defaultMode`** and **`defaultCaseSensitive`** — what the Replace screen starts on.

## Other database tables

**`tables`** — non-element tables Scrub may rewrite, for the `database` target (Pro).
Each entry names a table, the primary key, and the columns that hold text.

```php
'tables' => [
    [
        'table' => '{{%myplugin_snippets}}',
        'pk' => 'id',
        'columns' => ['body', 'footer'],
        'label' => 'Snippets',
    ],
],
```

Only the columns you list are ever read or written. A table that isn't in this list is
not reachable from the Replace screen at all, which is the point: this is an allow-list,
not a filter.

## Logging

**`logLevel`** — `debug`, `info`, `notice`, `warning` or `error`. Scrub keeps its own log
at `storage/logs/scrub.log`. A tool that edits content in bulk needs an audit trail that
survives the content.
