<?php

namespace justinholtweb\scrub\variables;

use justinholtweb\scrub\Plugin;

/**
 * `craft.scrub` — a small surface, on purpose.
 *
 * Templates get two things: the ability to apply the real-time rules to a string themselves, and a
 * way to ask whether a named rule is on. Everything else Scrub does belongs to the control panel or
 * the console, where there is a preview and an audit trail.
 */
class ScrubVariable
{
    /**
     * Applies the enabled real-time rules to a string.
     *
     * Useful where the automatic pass can't reach — a value rendered into a JSON response, an email
     * body, an attribute you'd rather the HTML-aware pass didn't skip.
     */
    public function apply(string $text, ?string $uri = null): string
    {
        return Plugin::getInstance()->realtime->apply($text, $uri);
    }

    /**
     * Whether a saved rule exists and is enabled.
     */
    public function isEnabled(string $handle): bool
    {
        return Plugin::getInstance()->rules->getByHandle($handle)?->enabled ?? false;
    }

    /**
     * Applies one named rule to a string, whether or not it's a real-time rule.
     *
     * The escape hatch for "this replacement only applies in one place in one template", which is a
     * perfectly reasonable thing to want and a terrible thing to express as a scope.
     */
    public function rule(string $handle, string $text): string
    {
        $rule = Plugin::getInstance()->rules->getByHandle($handle);

        if ($rule === null || !$rule->enabled) {
            return $text;
        }

        try {
            return $rule->matcher()->replace($text);
        } catch (\Throwable) {
            return $text;
        }
    }
}
