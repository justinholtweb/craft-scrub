---
title: History and undo
slug: undo
order: 50
summary: The run ledger, what undo restores, and the one thing it refuses to do.
---

# History and undo

## Every run is recorded

**Scrub → History** lists every run: what was searched for, what it was replaced with,
the mode, the scope, who ran it, when, how many places changed, and whether it has been
undone.

Open one and you get the per-value detail. Every changed value is stored before and after,
which is what makes the undo exact rather than approximate.

There is also a log at `storage/logs/scrub.log`. The ledger in the database is the
convenient copy. The log file is the one that is still there after somebody restores a
backup to undo the thing the ledger recorded.

## Undo

Press Undo on a run and every value goes back to exactly what it was. Not "the replacement
run backwards", which is a different and worse thing: replacing `gadget` with `widget`
would also hit the gadgets that were always called that. Scrub restores the stored
original, value by value.

```sh
php craft scrub/run/revert 42
```

## What undo refuses to do

**It will not overwrite an edit made since the run.**

If somebody has changed a value after Scrub changed it, restoring the original would throw
away their work, silently, on the assumption that the rename was the more recent intention.
It wasn't. Scrub skips those values, restores everything else, and reports exactly which
ones it left alone and why.

This is the thing a database backup cannot do. Restoring a backup to undo a bad rename
discards every edit anyone made in between, which is why nobody ever actually does it.

## Retention

Undo records are kept for **90 days** by default (`undoRetentionDays`). Set it to 0 to
keep them forever.

Ninety days is long enough that "we noticed last month" is still recoverable, and short
enough that a site doing weekly bulk edits doesn't end up with a ledger bigger than its
content. The run itself stays in the history either way; it's the per-value detail that
ages out, and with it the ability to undo.

## Runs that can't be undone

A run recorded with `recordUndo` turned off has no ledger, so there is nothing to restore
from. The history still shows that it happened.

Turning `recordUndo` off costs you the only safety net in the plugin and saves one database
row per changed value. There is very nearly no reason to do it.
