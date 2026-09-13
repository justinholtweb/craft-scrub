---
title: Troubleshooting
slug: troubleshooting
order: 80
summary: The things that go wrong, and what each one actually means.
---

# Troubleshooting

## The preview found nothing, but I can see the text on the page

Three usual causes.

**It's in a field the scope excluded.** Clear the field and entry-type filters and
preview again. If it appears, the text isn't where you thought it was.

**It's not stored content.** Text rendered from a template, a global set, a plugin's own
table or a static translation is on the page but isn't in an element field. Check the
`database` target (Pro) if it's in a table, or search your templates if it isn't.

**The field is protected.** `protectedFields` is enforced silently by the scanner, on
purpose. Check Settings.

## The preview found more than I expected

Almost always plain mode matching inside longer words. Switch to **whole word** and
preview again.

If it's a regular expression, remember that `.` matches any character and `*` is greedy.
`<p>.*</p>` matches from the first `<p>` to the *last* `</p>` on the page.

## "This run would change more than 5,000 places"

The ceiling did its job. Either the scope is wider than you meant, or you really do want
to change five thousand things.

Narrow the scope first and look at the number again. If it's genuinely right, raise
`maxUnitsPerRun` for the run and put it back afterwards.

## The run finished but the site still shows the old text

**Caches.** A safe-mode run invalidates them. A fast-mode run does not, because it bypasses
the element save entirely. Clear caches, or use safe mode.

**A template.** If the text is hard-coded in Twig, nothing in the database will fix it.

**A real-time rule pointing the other way.** Check **Rules** for an enabled real-time rule
rewriting your new text back to the old one.

## Search results are wrong after a fast run

Fast mode queues a re-index rather than doing it inline, so results lag until the queue
drains. Check **Utilities → Queue Manager**. If the queue isn't running at all, that's the
actual problem and it predates Scrub.

## Undo skipped some values

Working as intended. Those values were edited after the run, and restoring them would
throw away somebody's work. The report names each one. Fix them by hand, or run a new,
narrower replacement over just those.

## Undo isn't offered on an old run

Undo records are kept for `undoRetentionDays`, 90 by default. Past that the per-value
detail is gone and only the run's summary remains. Set it to 0 to keep them forever.

## A regular expression is rejected

The error from PHP's PCRE is shown as-is. The usual ones are an unclosed group, an
unescaped `{`, or a lookbehind that isn't fixed width.

You don't need to escape delimiters. Scrub picks one that doesn't appear in your pattern.

## Real-time rules aren't applying

All four of these have to be true: the edition is Pro, `realtimeEnabled` is on, the rule
is enabled *and* marked real-time, and the request is a front-end request. Real-time rules
deliberately never apply in the control panel, so what an editor sees in the field is what
is actually stored.

Also check that the text is emitted by the page template. The hook runs on the page
template only.

## Class names and attributes got rewritten

The rule has **HTML aware** turned off. Turn it on and the replacement applies to text
only.

## The run is stuck in the queue

A run over `queueThreshold` places goes to the queue and needs a queue runner. If Craft's
queue only runs on web requests, a large run on a quiet site can sit there. Run it
manually to confirm:

```sh
php craft queue/run
```

## Where to look when none of this helps

`storage/logs/scrub.log`, at `debug` level. Every scan, match and write is in there, and
it survives a database restore.
