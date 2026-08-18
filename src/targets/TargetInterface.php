<?php

namespace justinholtweb\scrub\targets;

use justinholtweb\scrub\models\Rule;

/**
 * Somewhere text lives.
 *
 * Scrub's scanner knows nothing about entries, assets or database tables. It asks each target for
 * units, runs the matcher over their values, and hands the changed values back to the same target to
 * write. That is the whole extension point: a plugin that stores prose in its own table implements
 * three methods and gets preview, undo, history, the console command and real-time exclusion for
 * free.
 */
interface TargetInterface
{
    /**
     * A stable handle. Stored in rules and in the undo ledger, so renaming one orphans its history.
     */
    public static function handle(): string;

    public function label(): string;

    /**
     * One sentence, shown beside the checkbox on the Replace screen.
     */
    public function description(): string;

    /**
     * `Plugin::EDITION_LITE` or `Plugin::EDITION_PRO`.
     */
    public function edition(): string;

    /**
     * Whether this target can run here at all — the plugin it reads is installed, the table exists.
     * An unsupported target is hidden rather than shown broken.
     */
    public function isSupported(): bool;

    /**
     * Everything in scope that could contain a match.
     *
     * Implementations should return a Generator: a scan over a large site holds one unit in memory
     * at a time, and the caller may stop early.
     *
     * @return iterable<Unit>
     */
    public function units(Rule $rule): iterable;

    /**
     * Re-resolves a single unit from its ref. Used by revert, hours or weeks later, so it must cope
     * with the thing having been deleted since — return null rather than throwing.
     */
    public function unit(string $ref): ?Unit;

    /**
     * Writes new values back.
     *
     * @param array<string, mixed> $values Slot handle => new value. Only changed slots are passed.
     * @throws \Throwable if the write fails. The caller records the failure against the unit and
     *                    carries on with the rest of the run.
     */
    public function write(Unit $unit, array $values): void;
}
