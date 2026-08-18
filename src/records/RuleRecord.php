<?php

namespace justinholtweb\scrub\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string|null $handle
 * @property bool $enabled
 * @property string $find
 * @property string|null $replace
 * @property string $mode
 * @property bool $caseSensitive
 * @property bool $preserveCase
 * @property bool $multiline
 * @property bool $dotAll
 * @property array|string|null $targets
 * @property array|string|null $scope
 * @property bool $realtime
 * @property array|string|null $realtimeUris
 * @property bool $realtimeHtmlAware
 * @property bool $onSave
 * @property string|null $cadence
 * @property string|null $dateLastRun
 * @property int $sortOrder
 */
class RuleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%scrub_rules}}';
    }
}
