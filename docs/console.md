---
title: Console commands
slug: console
order: 60
summary: Running replacements, rules and undo from the command line.
---

# Console commands

Every command respects the same protected fields, the same run ceiling and the same
exclusion of drafts, revisions and trashed elements as the control panel. The guardrails
live in the scanner, not in the form.

## scrub/run/replace

A one-off replacement.

```sh
php craft scrub/run/replace \
  --find="Acme Ltd" \
  --replace="Acme Inc" \
  --mode=word \
  --sources=section:3 \
  --dry-run=1
```

| Option | Default | |
| --- | --- | --- |
| `--find` | | What to look for |
| `--replace` | | What to put there. Omit it to delete the match |
| `--mode` | `plain` | `plain`, `word` or `regex` |
| `--case-sensitive` | `1` | |
| `--preserve-case` | `0` | Keep each match's own capitalisation (Pro) |
| `--targets` | `content` | Comma separated: `content`, `slugs`, `assets`, `database` |
| `--sources` | | `section:3,volume:1` |
| `--fields` | | Field handles, comma separated |
| `--sites` | | Site handles or IDs |
| `--elements` | | Explicit element IDs |
| `--drafts` | `0` | Include drafts |
| `--revisions` | `0` | Include revisions |
| `--dry-run` | `1` | |

**`--dry-run` defaults to 1.** Running the command with no flags previews; it does not
write. You have to ask for the write with `--dry-run=0`. A default that edits content
because somebody forgot an argument is not a default worth having.

## scrub/run/rule

Run a saved rule by handle, with the scope it was saved with.

```sh
php craft scrub/run/rule fix-legacy-links --dry-run=0
```

## scrub/run/due

Run every scheduled rule that is currently due, and nothing else.

```sh
php craft scrub/run/due
```

Safe to call more often than the shortest cadence, so an hourly cron is fine even when
every rule is monthly. This is the command to put in cron.

## scrub/run/revert

Undo a run by its ID.

```sh
php craft scrub/run/revert 42
```

Values edited since the run are skipped and reported, exactly as in the control panel.

## scrub/run/rules

List saved rules, their handles, and what each one does.

```sh
php craft scrub/run/rules
```
