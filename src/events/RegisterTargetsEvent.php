<?php

namespace justinholtweb\scrub\events;

use craft\base\Event;
use justinholtweb\scrub\targets\TargetInterface;

/**
 * Raised so other plugins can add somewhere else for Scrub to look.
 */
class RegisterTargetsEvent extends Event
{
    /** @var TargetInterface[] Keyed by handle. */
    public array $targets = [];
}
