---
title: Scope and targets
slug: scope
order: 30
summary: Stopping the run at the content you meant, and the places Scrub can write besides entry content.
---

# Scope and targets

## Targets

A target is a *kind of place text lives*. Scrub ships four, and the scan asks each one
for the values it holds.

| Target | Key | Edition | What it rewrites |
| --- | --- | --- | --- |
| Content | `content` | Lite | Element field content and titles |
| Slugs | `slugs` | Lite | Element slugs, and the URIs derived from them |
| Assets | `assets` | Pro | Asset filenames and alt text |
| Database | `database` | Pro | Rows in tables you configure |

Content is the default and is what you want almost every time. The others are opt-in per
run, because renaming a file on disk and rewriting a slug that something out there links
to are not things to do by accident.

Plugins can add their own. See [Extending Scrub](https://justinholt.com/plugins/craft-scrub/docs/extending).

## Scoping a run

Everything below narrows the scan. Leave them all empty and Scrub looks at all content in
all sites, which is occasionally what you want and usually isn't.

**Element type** — entries, categories, assets, users, and anything else registered.

**Sources** — sections, volumes, category groups. The specific buckets.

**Entry types** — narrower than a section. Rename something only in your `product`
entries and leave `article` alone.

**Fields** — individual field handles. If you know the text is in `body`, say so: the
scan gets faster and the blast radius gets smaller.

**Sites** — one site, some sites, or all of them. A rename that should only apply to the
UK site is a scope, not a second run you have to remember to stop.

**Element IDs** — an explicit list. The narrowest possible scope, and the right one when
somebody has sent you a spreadsheet of the nine entries that are wrong.

## Drafts, revisions and the trash

All three are **excluded** unless you ask for them.

Revisions especially. Rewriting a revision doesn't correct history, it falsifies it. The
revision is the record of what the page said in March; if you rewrite it, you have
destroyed the only evidence that the change ever happened, which is the thing you'd want
if the rename turns out to have been wrong.

Drafts are excluded because a draft belongs to whoever is working on it, and having the
sentence they're midway through editing change under them is startling at best.

## Protected fields and sources

Two settings put things permanently out of reach:

```php
'protectedFields' => ['stripeCustomerId', 'legacyImportKey'],
'protectedSources' => ['section:3', 'volume:1'],
```

These are checked by the scanner, not by the form. That means the console command and the
queue job are bound by them too, and a rule saved before you added the protection stops
working the moment you add it. They are the answer to "there is one field on this site
that nobody may ever bulk-edit, including me, including by accident, including at 6pm on a
Friday."

## Safe writes and fast writes

**Safe** is the default. Scrub saves the element the way Craft does: the search index
updates, caches invalidate, `afterSave` fires in every plugin listening for it, and an
entry gets a revision.

**Fast** writes the content column directly, which is what Craft's own utility does. It
is genuinely faster, by an order of magnitude on a large site, and it skips all of the
above. Scrub queues the re-index that Craft's utility forgets, but nothing else happens.

Use fast when the safe path would take hours and you know what you're giving up. Don't
use it because it sounds better.
