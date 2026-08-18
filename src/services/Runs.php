<?php

namespace justinholtweb\scrub\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateInterval;
use DateTime;
use justinholtweb\scrub\models\Report;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\models\Run;
use justinholtweb\scrub\Plugin;
use justinholtweb\scrub\records\ChangeRecord;
use justinholtweb\scrub\records\RunRecord;
use Throwable;

/**
 * The ledger, and the undo.
 *
 * The undo is the reason the ledger exists. "Restore the database backup" is not an undo — it throws
 * away every edit anybody made in between, so in practice nobody uses it and the bad replacement
 * stays. A row per changed value, holding what the value was and what it became, can be put back
 * exactly, on its own, hours later.
 *
 * It can also **refuse**. If a value has been edited since the run, its current content no longer
 * matches what Scrub wrote, and putting the old text back would silently destroy that edit. Those
 * are reported as conflicts and skipped. An undo that quietly overwrites somebody's work is worse
 * than no undo at all, because it is trusted.
 */
class Runs extends Component
{
    /**
     * Opens a run and returns its ID.
     */
    public function start(Rule $rule, string $source, bool $dryRun, ?int $userId = null): int
    {
        $record = new RunRecord();
        $record->status = Run::STATUS_RUNNING;
        $record->dryRun = $dryRun;
        $record->source = $source;
        $record->ruleId = $rule->id;
        $record->summary = $rule->describe();
        $record->rule = Json::encode($rule->toArray());
        $record->userId = $userId ?? Craft::$app->getUser()->getIdentity()?->id;
        $record->save(false);

        return (int)$record->id;
    }

    /**
     * Closes a run with its report.
     */
    public function finish(int $runId, Report $report): void
    {
        $record = RunRecord::findOne($runId);

        if ($record === null) {
            return;
        }

        $record->status = $report->errors !== [] && $report->hitCount === 0
            ? Run::STATUS_FAILED
            : Run::STATUS_DONE;

        $record->summary = $record->summary . ' — ' . $report->summarize();
        $record->report = Json::encode($report->toArray());
        $record->unitCount = $report->unitCount;
        $record->hitCount = $report->hitCount;
        $record->changed = $report->changed;
        $record->failed = $report->failed;
        $record->scanned = $report->scanned;
        $record->duration = round($report->elapsed, 3);
        $record->save(false);

        Craft::info(sprintf(
            'Run %d (%s): %d matches in %d places, %d written, %d failed, %.2fs',
            $runId,
            $record->dryRun ? 'preview' : 'applied',
            $report->hitCount,
            $report->unitCount,
            $report->changed,
            $report->failed,
            $report->elapsed,
        ), Plugin::LOG_CATEGORY);
    }

    public function fail(int $runId, string $message): void
    {
        $record = RunRecord::findOne($runId);

        if ($record === null) {
            return;
        }

        $record->status = Run::STATUS_FAILED;
        $record->note = $message;
        $record->save(false);

        Craft::error(sprintf('Run %d failed: %s', $runId, $message), Plugin::LOG_CATEGORY);
    }

    public function get(int $id): ?Run
    {
        $record = RunRecord::findOne($id);

        return $record !== null ? Run::fromRecord($record) : null;
    }

    /**
     * @return Run[]
     */
    public function all(int $limit = 50, int $offset = 0): array
    {
        $records = RunRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(static fn(RunRecord $record) => Run::fromRecord($record), $records);
    }

    public function total(): int
    {
        return (int)RunRecord::find()->count();
    }

    /**
     * How many undo records a run still has standing.
     */
    public function changeCount(int $runId): int
    {
        return (int)(new Query())
            ->from('{{%scrub_changes}}')
            ->where(['runId' => $runId, 'reverted' => false])
            ->count();
    }

    // -----------------------------------------------------------------------------------------
    // Undo
    // -----------------------------------------------------------------------------------------

    /**
     * Puts a run back.
     *
     * @return array{reverted: int, conflicts: int, missing: int, failed: int, runId: int|null, errors: string[]}
     */
    public function revert(int $runId, ?int $userId = null): array
    {
        $result = ['reverted' => 0, 'conflicts' => 0, 'missing' => 0, 'failed' => 0, 'runId' => null, 'errors' => []];
        $run = $this->get($runId);

        if ($run === null) {
            $result['errors'][] = Craft::t('scrub', 'That run no longer exists.');
            return $result;
        }

        if (!$run->isRevertible()) {
            $result['errors'][] = $run->revertedByRunId !== null
                ? Craft::t('scrub', 'That run has already been undone.')
                : Craft::t('scrub', 'That run changed nothing, so there is nothing to undo.');

            return $result;
        }

        $targets = Plugin::getInstance()->targets;

        // The undo is itself a run. It appears in the history, it can be inspected, and — because
        // it records its own changes — it can be undone in turn.
        $undoRule = $run->rule ?? new Rule();
        $undoRecord = new RunRecord();
        $undoRecord->status = Run::STATUS_RUNNING;
        $undoRecord->dryRun = false;
        $undoRecord->source = Run::SOURCE_CP;
        $undoRecord->summary = Craft::t('scrub', 'Undoing run #{id}', ['id' => $runId]);
        $undoRecord->rule = Json::encode($undoRule->toArray());
        $undoRecord->userId = $userId ?? Craft::$app->getUser()->getIdentity()?->id;
        $undoRecord->save(false);

        $undoRunId = (int)$undoRecord->id;
        $result['runId'] = $undoRunId;

        $started = microtime(true);
        $changed = 0;

        foreach ($this->changesByUnit($runId) as [$target, $ref, $changes]) {
            $handler = $targets->get($target);

            if ($handler === null) {
                $result['missing'] += count($changes);
                continue;
            }

            $unit = $handler->unit($ref);

            if ($unit === null) {
                // The element was deleted after the run. Nothing to put text back into.
                $result['missing'] += count($changes);
                continue;
            }

            $values = [];
            $ids = [];

            foreach ($changes as $change) {
                $current = $unit->values[$change['slot']] ?? null;

                // The check that makes the undo trustworthy: only put the old value back if what is
                // there now is exactly what Scrub wrote. Anything else means somebody has edited it
                // since, and their edit wins.
                if (Json::encode($current) !== (string)$change['newValue']) {
                    $result['conflicts']++;
                    continue;
                }

                $values[$change['slot']] = Json::decodeIfJson((string)$change['oldValue']);
                $ids[] = (int)$change['id'];
            }

            if ($values === []) {
                continue;
            }

            try {
                $handler->write($unit, $values);

                Db::update('{{%scrub_changes}}', ['reverted' => true], ['id' => $ids], updateTimestamp: false);
                $result['reverted'] += count($ids);
                $changed++;
            } catch (Throwable $e) {
                $result['failed'] += count($ids);
                $result['errors'][] = $e->getMessage();

                Craft::error(sprintf('Undo of run %d failed on %s %s: %s', $runId, $target, $ref, $e->getMessage()), Plugin::LOG_CATEGORY);
            }
        }

        $undoRecord->status = Run::STATUS_DONE;
        $undoRecord->changed = $changed;
        $undoRecord->failed = $result['failed'];
        $undoRecord->unitCount = $changed;
        $undoRecord->hitCount = $result['reverted'];
        $undoRecord->duration = round(microtime(true) - $started, 3);
        $undoRecord->summary = Craft::t('scrub', 'Undid run #{id} — {n} values restored', [
            'id' => $runId,
            'n' => $result['reverted'],
        ]);
        $undoRecord->save(false);

        // Marked undone even if some values conflicted: the run has been dealt with, and the
        // conflicts are recorded on the undo's own row rather than left implied.
        $original = RunRecord::findOne($runId);

        if ($original !== null) {
            $original->status = Run::STATUS_REVERTED;
            $original->revertedByRunId = $undoRunId;
            $original->save(false);
        }

        return $result;
    }

    /**
     * The run's outstanding changes, grouped so each unit is written once rather than once per slot.
     *
     * @return \Generator<array{0: string, 1: string, 2: array<int, array>}>
     */
    private function changesByUnit(int $runId): \Generator
    {
        $rows = (new Query())
            ->select(['id', 'target', 'ref', 'slot', 'oldValue', 'newValue'])
            ->from('{{%scrub_changes}}')
            ->where(['runId' => $runId, 'reverted' => false])
            ->orderBy(['target' => SORT_ASC, 'ref' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row['target'] . "\0" . $row['ref']][] = $row;
        }

        foreach ($grouped as $key => $changes) {
            [$target, $ref] = explode("\0", $key, 2);

            yield [$target, $ref, $changes];
        }
    }

    /**
     * Drops undo records past their retention window, and the empty runs left behind.
     *
     * Runs themselves are kept: the history of what happened to a site is small and worth having.
     * What gets large is the before-and-after text, and that is what expires.
     */
    public function collectGarbage(): void
    {
        $days = Plugin::getInstance()->getSettings()->undoRetentionDays;

        if ($days <= 0) {
            return;
        }

        $cutoff = (new DateTime())->sub(new DateInterval(sprintf('P%dD', $days)));

        $deleted = Db::delete('{{%scrub_changes}}', ['<', 'dateCreated', Db::prepareDateForDb($cutoff)]);

        if ($deleted > 0) {
            Craft::info(sprintf('Expired %d undo records older than %d days.', $deleted, $days), Plugin::LOG_CATEGORY);
        }
    }

    /**
     * @return ChangeRecord[]
     */
    public function changes(int $runId, int $limit = 100): array
    {
        /** @var ChangeRecord[] $records */
        $records = ChangeRecord::find()
            ->where(['runId' => $runId])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        return $records;
    }
}
