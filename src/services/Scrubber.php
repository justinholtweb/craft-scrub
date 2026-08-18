<?php

namespace justinholtweb\scrub\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\Json;
use InvalidArgumentException;
use justinholtweb\scrub\events\ScrubEvent;
use justinholtweb\scrub\events\UnitEvent;
use justinholtweb\scrub\matching\TextWalker;
use justinholtweb\scrub\models\Report;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\models\UnitReport;
use justinholtweb\scrub\Plugin;
use justinholtweb\scrub\targets\TargetInterface;
use justinholtweb\scrub\targets\Unit;
use Throwable;

/**
 * Finds the matches, and — when it isn't a dry run — writes the replacements.
 *
 * Preview and execution are the same method with one boolean between them. That isn't tidiness; it
 * is the entire promise of the plugin. A preview produced by different code from the run is a
 * *prediction*, and predictions about a hundred thousand rows of other people's content are worth
 * very little. Here, the preview is the run with the last step skipped.
 */
class Scrubber extends Component
{
    /**
     * @event ScrubEvent Raised before anything is scanned. Cancellable — set `isValid` to false and
     *                   the run stops before reading a row.
     */
    public const EVENT_BEFORE_SCRUB = 'beforeScrub';

    /** @event ScrubEvent Raised once the run is over, dry or not. */
    public const EVENT_AFTER_SCRUB = 'afterScrub';

    /**
     * @event UnitEvent Raised for each unit about to be written. Cancellable per unit, and the new
     *                  values can be edited in place.
     */
    public const EVENT_BEFORE_WRITE_UNIT = 'beforeWriteUnit';

    /** How many undo rows to hold before writing them out. */
    private const CHANGE_BATCH = 250;

    /**
     * Finds everything the rule would change, and changes nothing.
     */
    public function preview(Rule $rule, ?int $cap = null): Report
    {
        return $this->scan($rule, true, null, null, $cap);
    }

    /**
     * Finds everything the rule would change, and changes it.
     *
     * @param int|null $runId The ledger row to file the undo records under. Without one, nothing is
     *                        recorded and nothing can be reverted — which is why every caller in the
     *                        plugin passes one.
     * @param callable|null $progress Called as `fn(int $done, int $total)`, for the queue job's bar.
     */
    public function apply(Rule $rule, ?int $runId = null, ?callable $progress = null): Report
    {
        return $this->scan($rule, false, $runId, $progress);
    }

    // -----------------------------------------------------------------------------------------

    private function scan(
        Rule $rule,
        bool $dryRun,
        ?int $runId = null,
        ?callable $progress = null,
        ?int $cap = null,
    ): Report {
        $settings = Plugin::getInstance()->getSettings();
        $started = microtime(true);

        $report = new Report();
        $report->dryRun = $dryRun;

        $cap ??= $settings->maxUnitsInPreview;
        $hitLimit = $settings->maxHitsPerUnit;

        // Checks that can fail before anything is read. Cheap, and they turn "the run did nothing"
        // into a sentence explaining why.
        foreach ($this->objections($rule) as $objection) {
            $report->errors[] = $objection;
        }

        if ($report->errors !== []) {
            $report->elapsed = microtime(true) - $started;
            return $report;
        }

        $event = new ScrubEvent(['rule' => $rule, 'dryRun' => $dryRun]);
        $this->trigger(self::EVENT_BEFORE_SCRUB, $event);

        if (!$event->isValid) {
            $report->errors[] = Craft::t('scrub', 'The run was cancelled by another plugin.');
            $report->elapsed = microtime(true) - $started;
            return $report;
        }

        $matcher = $rule->matcher();
        $changes = [];

        foreach ($this->targetsFor($rule, $report) as $target) {
            foreach ($target->units($rule) as $unit) {
                $report->scanned++;

                $unitReport = new UnitReport($unit->identity());
                $newValues = [];

                foreach ($unit->values as $slot => $value) {
                    $hits = [];
                    $count = 0;

                    try {
                        $replaced = TextWalker::replace($value, $matcher, $hits, $count, $hitLimit);
                    } catch (Throwable $e) {
                        // A pattern that blows PCRE's backtrack limit does so on one enormous field,
                        // not on the whole site. Record it against the unit and keep going.
                        $unitReport->status = UnitReport::STATUS_FAILED;
                        $unitReport->error = $e->getMessage();
                        continue;
                    }

                    if ($count > 0) {
                        $unitReport->addSlot($slot, $unit->slotLabel($slot), $hits, $count);
                        $newValues[$slot] = $replaced;
                    }
                }

                if ($newValues === []) {
                    if ($unitReport->error !== null) {
                        $report->failed++;
                        $report->add($unitReport, $cap);
                    }

                    continue;
                }

                if ($report->unitCount >= $settings->maxUnitsPerRun && $settings->maxUnitsPerRun > 0) {
                    $report->errors[] = Craft::t('scrub', 'More than {n} places match. Narrow the scope, or raise the ceiling in settings.', [
                        'n' => $settings->maxUnitsPerRun,
                    ]);

                    break 2;
                }

                if (!$dryRun) {
                    $this->write($rule, $target, $unit, $newValues, $unitReport, $report, $runId, $changes);

                    // Flushed as it goes. A run big enough to be worth previewing is big enough that
                    // holding every before-value in memory until the end would be the thing that
                    // kills it.
                    if (count($changes) >= self::CHANGE_BATCH) {
                        $this->recordChanges($changes);
                    }
                }

                $report->add($unitReport, $cap);

                if ($progress !== null) {
                    $progress($report->unitCount, 0);
                }
            }
        }

        if ($changes !== []) {
            $this->recordChanges($changes);
        }

        $report->elapsed = microtime(true) - $started;

        $this->trigger(self::EVENT_AFTER_SCRUB, new ScrubEvent([
            'rule' => $rule,
            'dryRun' => $dryRun,
            'report' => $report,
        ]));

        return $report;
    }

    /**
     * Writes one unit, and records how to put it back.
     *
     * Each unit is written on its own. A single transaction around a run of ten thousand elements
     * holds locks for minutes and rolls back nine thousand good replacements because of one bad one;
     * per-unit failure with a per-unit record in the report is the more useful shape.
     *
     * @param array<string, mixed> $newValues
     * @param array<int, array> $changes Appended to, flushed in batches by the caller.
     */
    private function write(
        Rule $rule,
        TargetInterface $target,
        Unit $unit,
        array $newValues,
        UnitReport $unitReport,
        Report $report,
        ?int $runId,
        array &$changes,
    ): void {
        $event = new UnitEvent(['rule' => $rule, 'unit' => $unit, 'values' => $newValues]);
        $this->trigger(self::EVENT_BEFORE_WRITE_UNIT, $event);

        if (!$event->isValid || $event->values === []) {
            $unitReport->status = UnitReport::STATUS_SKIPPED;
            return;
        }

        try {
            $target->write($unit, $event->values);

            $unitReport->status = UnitReport::STATUS_CHANGED;
            $report->changed++;

            // `$runId === null` means nobody is keeping a ledger for this run; `recordUndo` off
            // means the site has asked not to. Either way there is nothing to write back later, and
            // the run report says so rather than the undo button quietly doing nothing.
            if ($runId !== null && Plugin::getInstance()->getSettings()->recordUndo) {
                foreach ($event->values as $slot => $value) {
                    $changes[] = [
                        $runId,
                        $unit->target,
                        $unit->ref,
                        (string)$slot,
                        Json::encode($unit->values[$slot] ?? null),
                        Json::encode($value),
                        $unitReport->slots[$slot]['count'] ?? 0,
                    ];
                }
            }
        } catch (Throwable $e) {
            $unitReport->status = UnitReport::STATUS_FAILED;
            $unitReport->error = $e->getMessage();
            $report->failed++;

            Craft::error(sprintf('Failed to write %s %s: %s', $unit->target, $unit->ref, $e->getMessage()), Plugin::LOG_CATEGORY);
        }
    }

    /**
     * @param array<int, array> $changes
     */
    private function recordChanges(array &$changes): void
    {
        $now = Db::prepareDateForDb(new \DateTime());

        $rows = array_map(static function(array $change) use ($now) {
            $change[] = false;
            $change[] = $now;
            $change[] = $now;
            $change[] = \craft\helpers\StringHelper::UUID();
            return $change;
        }, $changes);

        Craft::$app->getDb()->createCommand()->batchInsert('{{%scrub_changes}}', [
            'runId', 'target', 'ref', 'slot', 'oldValue', 'newValue', 'hits',
            'reverted', 'dateCreated', 'dateUpdated', 'uid',
        ], $rows)->execute();

        $changes = [];
    }

    /**
     * Everything wrong with the rule that can be known before reading a row.
     *
     * @return string[]
     */
    private function objections(Rule $rule): array
    {
        $objections = [];

        if (trim($rule->find) === '') {
            $objections[] = Craft::t('scrub', 'There is nothing to find.');
        }

        // A replacement identical to the needle matches everything and changes nothing, which looks
        // exactly like a broken plugin from the outside.
        if ($rule->find === $rule->replace && !$rule->preserveCase) {
            $objections[] = Craft::t('scrub', 'The replacement is the same as the search, so nothing would change.');
        }

        if ($rule->needsPro() && !Plugin::getInstance()->isPro()) {
            $objections[] = Craft::t('scrub', 'That needs Scrub Pro.');
        }

        try {
            $rule->matcher();
        } catch (InvalidArgumentException $e) {
            $objections[] = $e->getMessage();
        }

        return $objections;
    }

    /**
     * @return TargetInterface[]
     */
    private function targetsFor(Rule $rule, Report $report): array
    {
        $available = Plugin::getInstance()->targets->available();
        $targets = [];

        foreach ($rule->targets as $handle) {
            if (isset($available[$handle])) {
                $targets[] = $available[$handle];
                continue;
            }

            $report->warnings[] = Craft::t('scrub', '“{handle}” is not available here, so it was skipped.', [
                'handle' => $handle,
            ]);
        }

        return $targets;
    }
}
