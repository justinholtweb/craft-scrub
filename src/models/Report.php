<?php

namespace justinholtweb\scrub\models;

use Craft;
use craft\base\Model;

/**
 * The result of a scan — whether or not anything was written.
 *
 * A preview and a completed run produce the same object. The only difference between them is
 * `dryRun`, which is also the only difference in the code path that produced them.
 */
class Report extends Model
{
    /** @var UnitReport[] Bounded by the preview cap; `unitCount` is the real number. */
    public array $units = [];

    /** How many units contained at least one match. */
    public int $unitCount = 0;

    /** How many individual replacements the rule would make. */
    public int $hitCount = 0;

    /** How many units were examined to find them. */
    public int $scanned = 0;

    public int $changed = 0;
    public int $failed = 0;

    public float $elapsed = 0.0;
    public bool $dryRun = true;

    /** Whether `units` stops short of `unitCount`. */
    public bool $truncated = false;

    /** @var string[] Reasons the run cannot proceed. */
    public array $errors = [];

    /** @var string[] Things worth knowing that don't stop it. */
    public array $warnings = [];

    /** @var array<string, int> Hits per target handle, for the summary line. */
    public array $byTarget = [];

    /** @var array<string, int> Hits per element kind. */
    public array $byKind = [];

    public function add(UnitReport $unit, int $cap): void
    {
        $this->unitCount++;
        $this->hitCount += $unit->count;

        $target = $unit->identity['target'] ?? 'unknown';
        $this->byTarget[$target] = ($this->byTarget[$target] ?? 0) + $unit->count;

        $kind = $unit->kind();
        $this->byKind[$kind] = ($this->byKind[$kind] ?? 0) + $unit->count;

        if (count($this->units) < $cap) {
            $this->units[] = $unit;
        } else {
            $this->truncated = true;
        }
    }

    public function isEmpty(): bool
    {
        return $this->hitCount === 0;
    }

    public function canRun(): bool
    {
        return $this->errors === [] && $this->hitCount > 0;
    }

    /**
     * A sentence for the ledger and the flash message.
     */
    public function summarize(): string
    {
        if ($this->hitCount === 0) {
            return Craft::t('scrub', 'Nothing matched.');
        }

        return Craft::t('scrub', '{hits, plural, =1{One match} other{# matches}} in {units, plural, =1{one place} other{# places}}', [
            'hits' => $this->hitCount,
            'units' => $this->unitCount,
        ]);
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'unitCount' => $this->unitCount,
            'hitCount' => $this->hitCount,
            'scanned' => $this->scanned,
            'changed' => $this->changed,
            'failed' => $this->failed,
            'elapsed' => round($this->elapsed, 3),
            'dryRun' => $this->dryRun,
            'truncated' => $this->truncated,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'byTarget' => $this->byTarget,
            'byKind' => $this->byKind,
            'units' => array_map(static fn(UnitReport $unit) => $unit->toArray(), $this->units),
        ];
    }

    public static function fromArray(?array $array): self
    {
        $report = new self();

        foreach ($array ?? [] as $key => $value) {
            if ($key !== 'units' && $report->canSetProperty($key)) {
                $report->$key = $value;
            }
        }

        $report->units = array_map(
            static fn(array $unit) => UnitReport::fromArray($unit),
            $array['units'] ?? [],
        );

        return $report;
    }
}
