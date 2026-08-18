<?php

namespace justinholtweb\scrub\events;

use craft\events\CancelableEvent;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\targets\Unit;

/**
 * Raised for each unit that is about to be written.
 *
 * Cancellable, per unit: `$event->isValid = false` skips this one and leaves the rest of the run
 * alone. `$values` may be edited in place, which is the escape hatch for "replace it, but not in
 * this one field of this one section".
 */
class UnitEvent extends CancelableEvent
{
    public Rule $rule;
    public Unit $unit;

    /** @var array<string, mixed> Slot handle => new value. Editable. */
    public array $values = [];
}
