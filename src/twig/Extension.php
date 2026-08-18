<?php

namespace justinholtweb\scrub\twig;

use justinholtweb\scrub\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Adds `|scrub`.
 *
 * `{{ entry.body|scrub }}` applies the real-time rules to one value rather than to the whole page —
 * for the sites that would rather be explicit about where output is rewritten than have it happen
 * everywhere.
 */
class Extension extends AbstractExtension
{
    public function getName(): string
    {
        return 'scrub';
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('scrub', [$this, 'scrub'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param string|null $rule A saved rule's handle, to apply only that one.
     */
    public function scrub(mixed $value, ?string $rule = null): string
    {
        $text = (string)$value;

        if ($text === '') {
            return $text;
        }

        $plugin = Plugin::getInstance();

        return $rule !== null
            ? (new \justinholtweb\scrub\variables\ScrubVariable())->rule($rule, $text)
            : $plugin->realtime->apply($text);
    }
}
