<?php

namespace justinholtweb\scrub\records;

use craft\db\ActiveRecord;

/**
 * One value, before and after.
 *
 * @property int $id
 * @property int $runId
 * @property string $target
 * @property string $ref
 * @property string $slot
 * @property string|null $oldValue
 * @property string|null $newValue
 * @property int $hits
 * @property bool $reverted
 */
class ChangeRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%scrub_changes}}';
    }
}
