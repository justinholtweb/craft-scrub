---
title: Installation
slug: installation
order: 10
summary: Requirements, install, editions, and what changes the moment it's switched on.
---

# Installation

## Requirements

| | |
| --- | --- |
| Craft CMS | 5.0 or later |
| PHP | 8.2 or later |
| Database | MySQL 8.0+ / MariaDB 10.5+ / PostgreSQL 13+ |

## Install

From the Plugin Store, search for **Scrub** and click Install.

Or from the command line:

```sh
composer require justinholtweb/craft-scrub
php craft plugin/install scrub
```

## Editions

Scrub has two editions. Lite is free and is not a trial: it is the whole
find-and-replace tool, with the preview, the scoping, the run history and the undo.
Pro adds the parts that either run on their own or reach outside your entries.

| | Lite | Pro |
| --- | --- | --- |
| Preview before writing | ✓ | ✓ |
| Plain and whole-word matching | ✓ | ✓ |
| Scope by section, volume, type, field, site, ID | ✓ | ✓ |
| Undo, from the per-value ledger | ✓ | ✓ |
| Run history | ✓ | ✓ |
| Console command | ✓ | ✓ |
| Slug and URI rewriting | ✓ | ✓ |
| Regular expressions, with capture groups | | ✓ |
| Case-preserving replacement | | ✓ |
| Saved rules | | ✓ |
| Real-time rules, applied as pages render | | ✓ |
| On-save rules | | ✓ |
| Asset filenames and alt text | | ✓ |
| Other database tables | | ✓ |

Pro is **$99** once, with a **$79/year** renewal for updates. Switch editions in the
Plugin Store; nothing you already ran is affected either way.

## What changes when you install it

**Craft's own Find and Replace utility is hidden.** Two find-and-replace tools sitting
side by side, one of which rewrites revisions and leaves the search index describing
content that no longer exists, is worse than either one alone. The core utility is only
hidden, never removed. Turn off `replaceCoreUtility` in Settings and it comes straight
back.

**Nobody gets access until you grant it.** Scrub ships five separate permissions, and an
admin has them all. Previewing and running are deliberately different permissions, so an
editor can be trusted to look without being trusted to write:

- **Preview replacements and read the history**
- **Run replacements**, and under it, **Rewrite non-element database tables**
- **Undo a run**
- **Manage saved rules**

## First run

Go to **Scrub → Replace**, type something you know appears in a handful of entries, and
press Preview. Nothing is written. You'll get the list of every place it matched, with
the old text and the new text side by side, and a count of anything too numerous to list.

Read it, then press Replace.
