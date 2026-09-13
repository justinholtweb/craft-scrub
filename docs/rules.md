---
title: Saved rules
slug: rules
order: 40
summary: Rules you keep, rules that run as pages render, and rules that run when content is saved.
---

# Saved rules

A rule is a find-and-replace you keep. It has a name, a handle, a scope, and all the
matching options from the Replace screen. Saved rules are Pro.

They are worth having for three quite different reasons.

## Rules you run by hand

The plainest use. You run the same replacement every few months, or you want a colleague
to run it without retyping a regular expression that took you twenty minutes to get right.

Save it once, then run it from **Scrub → Rules**, or from the console:

```sh
php craft scrub/run/rule fix-legacy-links
```

The rule carries its own scope, so it stops where it stopped last time.

## Real-time rules

A real-time rule rewrites **rendered output** rather than stored content. Nothing in the
database changes. Turning the rule off is the undo.

That sounds like a lesser version of the real thing, and it's the opposite. It's for the
replacements you can't safely commit to. A brand is mid-rename and legal hasn't signed
off. A supplier's name is wrong in four thousand places and you'd like the site to be
right today while you work out whether the underlying content should change. A phone
number changed and you do not want to touch nine years of blog posts to fix it.

**It's HTML-aware.** The replacement runs over text, not over markup, so a rule that
changes `widget` to `gadget` doesn't touch `class="widget-grid"`, `<a href="/widgets">`
or an `alt` attribute you didn't mean to rewrite. Turn off **HTML aware** on the rule if
you genuinely want to rewrite attributes too.

**It's cheap when it's off.** The hook attaches to the page template only, and only when
Pro is active, real-time is enabled, and at least one real-time rule exists. On a site
with none, a front-end request never touches Scrub at all. The list of active rules is
cached, so the question "are there any?" is not a database query per request.

It applies to the page template, not to every partial. A partial rendered inside a page
would otherwise be rewritten once on its own and again as part of the page around it.

## On-save rules

An on-save rule runs when an element is saved. New content gets the replacement without
anybody remembering to go and apply it.

This is the one for a rule that will never be finished: an old product name that keeps
coming back because it's still in somebody's copy-paste buffer, a supplier's address that
three people type from memory, a unit that should always be written `kg` and is sometimes
written `Kg`.

On-save rules write to the database, so they are recorded and undoable like any other run.

## Scheduled rules

A rule can carry a cadence of **daily**, **weekly** or **monthly**. Due rules run from:

```sh
php craft scrub/run/due
```

Put that on a cron and the rule keeps the site tidy on its own. `due` runs only what is
actually due, so calling it hourly is harmless.

## Precedence and order

Rules have a sort order and run in it. A rule that is disabled does not run, in any mode.
The Pro gate is checked at run time, not at save time, so a site that drops back to Lite
keeps its rules and stops applying them, rather than losing them.
