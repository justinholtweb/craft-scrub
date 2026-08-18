<?php

namespace justinholtweb\scrub\targets;

/**
 * One addressable thing that holds text: an entry in a site, an asset's metadata, a row in a
 * plugin's table.
 *
 * A unit is what the scanner iterates, what the preview groups hits under, and what the writer
 * writes back. Its `ref` is the only identity that has to survive being written to the ledger and
 * read back an hour later by a revert, so it is a string the target itself knows how to resolve —
 * not an object, and not an offset into a result set that no longer exists.
 */
final class Unit
{
    /**
     * @param string $target The target handle this unit came from.
     * @param string $ref Identity within the target, resolvable by `TargetInterface::unit()`.
     * @param string $kind What sort of thing it is, for grouping — "Entry", "Asset", "Form".
     * @param string $label How to name it to a person.
     * @param string|null $cpUrl Where to go and look at it.
     * @param array<string, mixed> $values Slot handle => current value. Strings and arrays of them.
     * @param array<string, string> $slotLabels Slot handle => how to name the slot.
     * @param mixed $subject The loaded object behind the unit, if the target already has it. Never
     *                       serialized; it exists so a scan doesn't load every element twice.
     * @param string|null $siteName Which site this copy belongs to, where that's meaningful.
     */
    public function __construct(
        public readonly string $target,
        public readonly string $ref,
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $cpUrl = null,
        public readonly array $values = [],
        public readonly array $slotLabels = [],
        public readonly mixed $subject = null,
        public readonly ?string $siteName = null,
    ) {
    }

    public function slotLabel(string $slot): string
    {
        return $this->slotLabels[$slot] ?? $slot;
    }

    /**
     * The unit without its subject, for the ledger.
     *
     * @return array{target: string, ref: string, kind: string, label: string, cpUrl: string|null, siteName: string|null}
     */
    public function identity(): array
    {
        return [
            'target' => $this->target,
            'ref' => $this->ref,
            'kind' => $this->kind,
            'label' => $this->label,
            'cpUrl' => $this->cpUrl,
            'siteName' => $this->siteName,
        ];
    }
}
