<?php

namespace justinholtweb\scrub\targets;

use craft\base\Component;
use justinholtweb\scrub\Plugin;

/**
 * Shared defaults for targets. Everything here is the boring answer; a target overrides only what it
 * actually differs on.
 */
abstract class BaseTarget extends Component implements TargetInterface
{
    public function label(): string
    {
        return static::handle();
    }

    public function description(): string
    {
        return '';
    }

    public function edition(): string
    {
        return Plugin::EDITION_LITE;
    }

    public function isSupported(): bool
    {
        return true;
    }
}
