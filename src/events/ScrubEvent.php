<?php

namespace justinholtweb\scrub\events;

use craft\events\CancelableEvent;
use justinholtweb\scrub\models\Report;
use justinholtweb\scrub\models\Rule;

/**
 * Raised around a run.
 *
 * `beforeScrub` is cancellable — set `$event->isValid = false` and nothing is written. That is the
 * hook for a site with its own idea of what must never be rewritten.
 */
class ScrubEvent extends CancelableEvent
{
    public Rule $rule;
    public ?Report $report = null;
    public bool $dryRun = true;
}
