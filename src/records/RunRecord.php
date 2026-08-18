<?php

namespace justinholtweb\scrub\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $status
 * @property bool $dryRun
 * @property string $source
 * @property int|null $ruleId
 * @property string|null $summary
 * @property string|null $note
 * @property array|string|null $rule
 * @property array|string|null $report
 * @property int $unitCount
 * @property int $hitCount
 * @property int $changed
 * @property int $failed
 * @property int $scanned
 * @property float $duration
 * @property int|null $revertedByRunId
 * @property int|null $userId
 */
class RunRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%scrub_runs}}';
    }
}
