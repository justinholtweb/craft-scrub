<?php

namespace justinholtweb\scrub\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * One entry in the ledger: what ran, what it found, what it changed, and whether it has been undone.
 *
 * The rule is stored as it was at the moment it ran, not as a reference to a rule that may since
 * have been edited or deleted. An audit record that changes when something else changes is not an
 * audit record.
 */
class Run extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERTED = 'reverted';

    public const SOURCE_CP = 'cp';
    public const SOURCE_CONSOLE = 'console';
    public const SOURCE_QUEUE = 'queue';
    public const SOURCE_SAVE = 'save';
    public const SOURCE_SCHEDULE = 'schedule';

    public ?int $id = null;
    public string $status = self::STATUS_PENDING;
    public bool $dryRun = false;
    public string $source = self::SOURCE_CP;
    public ?int $ruleId = null;
    public ?string $summary = null;
    public ?string $note = null;

    public ?Rule $rule = null;
    public ?Report $report = null;

    public int $unitCount = 0;
    public int $hitCount = 0;
    public int $changed = 0;
    public int $failed = 0;
    public int $scanned = 0;
    public float $duration = 0.0;

    public ?int $revertedByRunId = null;
    public ?int $userId = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;

    public function isRevertible(): bool
    {
        return $this->status === self::STATUS_DONE
            && !$this->dryRun
            && $this->changed > 0
            && $this->revertedByRunId === null;
    }

    public function user(): ?User
    {
        return $this->userId !== null ? Craft::$app->getUsers()->getUserById($this->userId) : null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => Craft::t('scrub', 'Queued'),
            self::STATUS_RUNNING => Craft::t('scrub', 'Running'),
            self::STATUS_DONE => $this->dryRun ? Craft::t('scrub', 'Preview') : Craft::t('scrub', 'Done'),
            self::STATUS_FAILED => Craft::t('scrub', 'Failed'),
            self::STATUS_REVERTED => Craft::t('scrub', 'Undone'),
            default => $this->status,
        };
    }

    /**
     * Craft's own status colours, so the list reads the way every other index in the control panel
     * does without anybody having to learn what Scrub's colours mean.
     */
    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_DONE => $this->dryRun ? 'blue' : 'green',
            self::STATUS_FAILED => 'red',
            self::STATUS_REVERTED => 'orange',
            self::STATUS_RUNNING, self::STATUS_PENDING => 'yellow',
            default => 'grey',
        };
    }

    public static function fromRecord(\justinholtweb\scrub\records\RunRecord $record): self
    {
        $run = new self();

        $run->id = (int)$record->id;
        $run->status = $record->status;
        $run->dryRun = (bool)$record->dryRun;
        $run->source = $record->source;
        $run->ruleId = $record->ruleId !== null ? (int)$record->ruleId : null;
        $run->summary = $record->summary;
        $run->note = $record->note;
        $run->rule = $record->rule !== null ? Rule::fromArray(self::decode($record->rule)) : null;
        $run->report = $record->report !== null ? Report::fromArray(self::decode($record->report)) : null;
        $run->unitCount = (int)$record->unitCount;
        $run->hitCount = (int)$record->hitCount;
        $run->changed = (int)$record->changed;
        $run->failed = (int)$record->failed;
        $run->scanned = (int)$record->scanned;
        $run->duration = (float)$record->duration;
        $run->revertedByRunId = $record->revertedByRunId !== null ? (int)$record->revertedByRunId : null;
        $run->userId = $record->userId !== null ? (int)$record->userId : null;
        $run->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;
        $run->dateUpdated = DateTimeHelper::toDateTime($record->dateUpdated) ?: null;

        return $run;
    }

    /**
     * MySQL hands back JSON columns as strings; Postgres hands back arrays. Both arrive here.
     */
    private static function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return \craft\helpers\Json::decodeIfJson($value) ?: null;
        }

        return null;
    }
}
