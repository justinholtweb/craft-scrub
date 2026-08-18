<?php

namespace justinholtweb\scrub\models;

use justinholtweb\scrub\matching\Hit;

/**
 * What a rule found in one unit, and what became of it.
 *
 * Written by the preview and re-used by the run, which is the point: the preview isn't an estimate
 * of what would happen, it's the first half of it.
 */
class UnitReport
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CHANGED = 'changed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /** @var array<string, array{label: string, hits: Hit[], count: int}> */
    public array $slots = [];

    public int $count = 0;
    public string $status = self::STATUS_PENDING;
    public ?string $error = null;

    /**
     * @param array{target: string, ref: string, kind: string, label: string, cpUrl: string|null, siteName: string|null} $identity
     */
    public function __construct(public array $identity)
    {
    }

    /**
     * @param Hit[] $hits
     */
    public function addSlot(string $slot, string $label, array $hits, int $count): void
    {
        $this->slots[$slot] = ['label' => $label, 'hits' => $hits, 'count' => $count];
        $this->count += $count;
    }

    public function label(): string
    {
        return $this->identity['label'] ?? '';
    }

    public function kind(): string
    {
        return $this->identity['kind'] ?? '';
    }

    public function cpUrl(): ?string
    {
        return $this->identity['cpUrl'] ?? null;
    }

    public function siteName(): ?string
    {
        return $this->identity['siteName'] ?? null;
    }

    public function toArray(): array
    {
        return [
            'identity' => $this->identity,
            'status' => $this->status,
            'error' => $this->error,
            'count' => $this->count,
            'slots' => array_map(static fn(array $slot) => [
                'label' => $slot['label'],
                'count' => $slot['count'],
                'hits' => array_map(static fn(Hit $hit) => $hit->toArray(), $slot['hits']),
            ], $this->slots),
        ];
    }

    public static function fromArray(array $array): self
    {
        $report = new self($array['identity'] ?? []);
        $report->status = $array['status'] ?? self::STATUS_PENDING;
        $report->error = $array['error'] ?? null;
        $report->count = (int)($array['count'] ?? 0);

        foreach ($array['slots'] ?? [] as $slot => $data) {
            $report->slots[$slot] = [
                'label' => $data['label'] ?? $slot,
                'count' => (int)($data['count'] ?? 0),
                'hits' => array_map(static fn(array $hit) => Hit::fromArray($hit), $data['hits'] ?? []),
            ];
        }

        return $report;
    }
}
