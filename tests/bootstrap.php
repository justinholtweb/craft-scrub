<?php

/**
 * Bootstrap for the unit suite.
 *
 * These run against plain PHP — no Craft application, no database. What they cover is the part of
 * Scrub that has to be exactly right and can be checked in isolation: how a find/replace definition
 * compiles into a pattern, what it matches, what it leaves alone, and how a replacement is cased.
 * Everything that reads or writes an element is exercised against the plugin-testing harness
 * instead, as described in tests/README.md.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
