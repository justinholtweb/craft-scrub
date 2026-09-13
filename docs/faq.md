---
title: FAQ
slug: faq
order: 90
summary: The questions worth answering before you install it.
---

# FAQ

## Is Scrub free?

Lite is, and it isn't a trial. The preview, the scoping, the run history, the undo, the
console command and slug rewriting are all in the free edition, with no limits on how much
you replace.

Pro is **$99** once with a **$79/year** renewal, and adds regular expressions,
case-preserving replacement, saved rules, real-time and on-save rules, asset filenames and
alt text, and the ability to rewrite other database tables.

## How is this different from Craft's own Find and Replace?

Craft's utility selects every row of `elements_sites` matching a `LIKE`, runs `str_replace`
over it and writes it back with raw SQL. Every element type, every site, drafts and
revisions included. There's no preview, no scope, no case handling, no word boundaries, no
regular expressions, no undo and no record that it ran. Because it bypasses the element
save, your search index is left describing content that no longer exists, and nothing tells
you.

Scrub answers each of those. That's the whole design brief.

## Can it break my site?

It can change a lot of content quickly, which is what it's for. The defaults are arranged
so that doing so by accident is hard: the preview shows you the real result before anything
is written, the run refuses past 5,000 places, drafts and revisions are excluded, elements
are saved properly, and every change is recorded so you can put it back.

The genuinely dangerous combination is fast write mode with `recordUndo` off and the
ceiling removed. All three have to be turned on deliberately.

## Is the preview accurate?

It's the run with the last step skipped. Same method, same scan, same matcher, one boolean
different. It isn't a separate estimate that can disagree with what happens.

## Does undo really work?

Every changed value is stored before and after, and undo restores the stored original
rather than running the replacement backwards.

It refuses to overwrite anything edited since the run, and reports what it skipped. That's
the part a database restore can't do, because a restore throws away every edit made in
between.

## Will it update my search index?

In safe mode, yes, because the element is saved normally. In fast mode the index would go
stale, so Scrub queues the re-index. Craft's own utility does neither.

## Can I use regular expressions?

In Pro. Capture groups work in the replacement as `$1`, `$2` and so on. `multiline` and
`dotAll` are separate switches rather than flags to remember, and you never escape
delimiters because Scrub picks one that isn't in your pattern.

## What are real-time rules for?

Rewriting what a page *shows* without changing what's stored. A rename that isn't signed
off yet, a supplier's name that's wrong in four thousand old posts, a phone number you
don't want to touch nine years of archives to fix.

Nothing in the database changes, so turning the rule off is the undo. They're HTML-aware,
so class names, hrefs and attributes survive.

## Will real-time rules slow my site down?

Not measurably, and not at all when you aren't using them. The hook is only attached when
the edition is Pro, the feature is enabled, and at least one real-time rule exists. On a
site with none, a front-end request never reaches Scrub's code. The active rule list is
cached, so there's no per-request query.

## Can it rename slugs and URIs?

Yes, in Lite, via the `slugs` target. It's off by default and opt-in per run, because
rewriting a URL that something out there links to isn't a thing to do by accident.

## Can it rewrite my plugin's own database table?

In Pro. Declare the table, its primary key and the text columns in `config/scrub.php`.
Only listed columns are ever read or written, and a table that isn't listed isn't
reachable from the Replace screen at all.

## Can it work on things other than entries and tables?

Yes. A target is three methods: hand Scrub the text, take the changed text back, say which
edition it needs. Implement them and you get preview, undo, history, scoping, the console
command and the run ledger for free. See [Extending Scrub](https://justinholt.com/plugins/craft-scrub/docs/extending).

## Does it touch drafts or revisions?

Not unless you ask. Rewriting a revision doesn't correct history, it falsifies it: the
revision is the evidence of what the page said before, which is exactly what you'd want if
the rename turns out to be wrong.

## Who can run it?

Whoever you grant it to. Previewing and running are separate permissions on purpose, so an
editor can be trusted to look without being trusted to write. Undo, managing rules and
rewriting database tables are three more.

## Does it work on multi-site installs?

Yes, and site is one of the scope filters, so a rename that should only apply to one site
is a scope rather than a second run you have to remember to stop.
