---
title: Finding and replacing
slug: usage
order: 20
summary: The Replace screen, the three matching modes, and why the preview can be trusted.
---

# Finding and replacing

Everything happens on **Scrub → Replace**. You fill in what to find, what to replace it
with, where to look, and press Preview.

## The preview is the run

This is the part worth understanding, because it's the reason to use Scrub at all.

The preview and the run are **the same method with one boolean between them**. Scrub
scans, matches, and computes every replacement exactly as it would for real, and then
either writes the result or throws it away. What you are shown is not a prediction of
what would happen. It is what happened, with the last step skipped.

That matters because the usual failure of a find-and-replace is not "it didn't match".
It's "it matched somewhere I forgot existed". A preview built from a separate, cheaper
code path can't tell you about those places, because it isn't looking the way the real
run looks.

The preview lists up to 200 places by default, and up to 10 individual matches inside
each one. Past that it stops listing and starts counting, so a run across four thousand
entries still tells you the total.

## The three modes

**Plain** matches the characters you typed, anywhere they appear. `cat` matches
`cat`, `catalogue` and `concatenate`.

**Whole word** matches only where the text stands on its own. `cat` matches `cat` and
`cat,` but not `catalogue`. This is the one you want for renaming a product or a company,
and it's the one people forget exists until after they've turned every `form` on the site
into a `formie`.

**Regular expression** (Pro) matches a pattern, with capture groups available in the
replacement as `$1`, `$2` and so on. Scrub picks a delimiter that doesn't appear in your
pattern, so you never escape slashes. `multiline` and `dotAll` are separate switches
rather than flags you have to remember.

```
Find:     (\d{4})-(\d{2})-(\d{2})
Replace:  $3/$2/$1

2026-08-18  →  18/08/2026
```

A malformed pattern is rejected at the preview, with the error, before anything runs.

## Case

**Case sensitive** is on by default, and is the ordinary meaning of it: `Acme` does not
match `acme`.

**Keep the original capitalisation** (Pro) is the interesting one. Turn it off and a
single rule handles every casing at once:

```
Find: widget   Replace: gadget

widget  →  gadget
Widget  →  Gadget
WIDGET  →  GADGET
```

It works word by word, not by copying the case of the whole string, which is what makes
multi-word replacements come out right. `Acme Ltd` → `Acme Inc`, not `acme inc`.

## Running it

Press Replace and one of two things happens.

Under 100 matching places, it runs in the request and you get the result immediately.
Over that, it's handed to the queue, because a request that saves eight hundred elements
is a request that times out. The threshold is `queueThreshold` and you can set it to 0 to
always run in the request.

Either way the run is recorded, every changed value is stored before and after, and the
run appears in **History** where it can be undone.

## The ceiling

By default no single run may change more than **5,000** places. If the preview says more,
the run refuses.

This is a guardrail against the mis-scoped rule, which is the way this goes wrong in
practice. You meant to rename a product inside one section and you left the scope empty.
Failing loudly at the preview is better than succeeding quietly across eighty thousand
entries. Raise `maxUnitsPerRun` when you genuinely mean it, or set it to 0 to remove the
ceiling.
