<?php

namespace justinholtweb\scrub\matching;

/**
 * One match, and what it would become.
 *
 * A value object rather than a model: hits are created in tens of thousands during a scan and never
 * validated, saved or edited. `before` and `after` carry the surrounding text so the preview can
 * show the match in context — which is the whole reason anyone trusts the preview.
 */
final class Hit
{
    public function __construct(
        public readonly string $matched,
        public readonly string $replacement,
        public readonly int $offset,
        public readonly string $before = '',
        public readonly string $after = '',
    ) {
    }

    /**
     * @return array{matched: string, replacement: string, offset: int, before: string, after: string}
     */
    public function toArray(): array
    {
        return [
            'matched' => $this->matched,
            'replacement' => $this->replacement,
            'offset' => $this->offset,
            'before' => $this->before,
            'after' => $this->after,
        ];
    }

    public static function fromArray(array $array): self
    {
        return new self(
            matched: (string)($array['matched'] ?? ''),
            replacement: (string)($array['replacement'] ?? ''),
            offset: (int)($array['offset'] ?? 0),
            before: (string)($array['before'] ?? ''),
            after: (string)($array['after'] ?? ''),
        );
    }
}
