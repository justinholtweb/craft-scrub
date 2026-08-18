<?php

namespace justinholtweb\scrub\models;

use craft\base\Model;
use Psr\Log\LogLevel;

/**
 * Scrub's settings.
 *
 * Nothing here is marked `required`. A `required` rule on any one setting makes
 * `savePluginSettings()` fail wholesale, so on a fresh install *no* setting can be saved until that
 * one is filled in — values are validated for correctness only when they're present.
 *
 * The defaults are the cautious ones, and the reason is worth stating plainly: this plugin edits
 * other people's content in bulk, and the failure mode isn't an error message, it's a site that
 * looks fine and is subtly wrong in four hundred places. So: the safe write path, revisions on,
 * undo recorded, a ceiling on how much one run may touch, and a preview before anything happens.
 * Everything faster is available and has to be asked for.
 */
class Settings extends Model
{
    /** Save the element, the way Craft does. */
    public const WRITE_SAFE = 'safe';

    /** Write the content column directly, the way Craft's own find-and-replace does. */
    public const WRITE_FAST = 'fast';

    // -------------------------------------------------------------------------------------
    // How it writes
    // -------------------------------------------------------------------------------------

    /**
     * @var string `WRITE_SAFE` or `WRITE_FAST`.
     *
     * Safe saves the element: the search index is updated, caches are invalidated, `afterSave`
     * fires in every plugin that's listening, and an entry gets a revision. Fast writes the content
     * JSON straight back and queues a re-index — none of the rest happens. Fast exists because on a
     * 200,000-element site the safe path is measured in hours; it is not the default because on
     * every other site the difference is seconds and the cost is invisible breakage.
     */
    public string $writeMode = self::WRITE_SAFE;

    /**
     * @var bool Whether changed entries get a revision.
     *
     * On. Scrub's own undo is more precise, but a revision is how an *editor* sees, in the interface
     * they already use, that their page changed and what it changed from. Turn it off for a run
     * across tens of thousands of entries, where the revisions cost more than they're worth.
     */
    public bool $createRevisions = true;

    /**
     * @var bool Whether every change is recorded so the run can be undone.
     *
     * On, and there is almost no reason to turn it off. The ledger costs one row per changed value.
     */
    public bool $recordUndo = true;

    /**
     * @var int Days to keep undo records. 0 keeps them forever.
     *
     * Ninety days is long enough that "we noticed last month" is still recoverable, and short enough
     * that a site doing weekly bulk edits doesn't accumulate a table larger than its content.
     */
    public int $undoRetentionDays = 90;

    // -------------------------------------------------------------------------------------
    // Guardrails
    // -------------------------------------------------------------------------------------

    /**
     * @var int The most places one run may change. 0 removes the ceiling.
     *
     * Intended to make a mis-scoped rule fail loudly at the preview rather than quietly across
     * 80,000 entries. Raising it is a deliberate act.
     */
    public int $maxUnitsPerRun = 5000;

    /** @var int How many places the preview lists before it stops listing and starts counting. */
    public int $maxUnitsInPreview = 200;

    /** @var int How many individual matches are shown per place. The rest are counted. */
    public int $maxHitsPerUnit = 10;

    /**
     * @var int Above this many matching places, the run is handed to the queue instead of being run
     *          in the request. 0 always runs in the request.
     */
    public int $queueThreshold = 100;

    /**
     * @var string[] Field handles no rule may ever change, whatever the form says. Checked
     *               server-side, on every run, including console runs.
     */
    public array $protectedFields = [];

    /**
     * @var string[] Source keys — `section:3`, `volume:1` — that no rule may ever change.
     */
    public array $protectedSources = [];

    // -------------------------------------------------------------------------------------
    // Real-time rules
    // -------------------------------------------------------------------------------------

    /**
     * @var bool Master switch for render-time replacement.
     *
     * Separate from the per-rule setting so that a site with a performance problem can turn the
     * whole feature off in one place without editing every rule — and so that a staging environment
     * can differ from production through `config/scrub.php`.
     */
    public bool $realtimeEnabled = true;

    // -------------------------------------------------------------------------------------
    // Defaults for the Replace screen
    // -------------------------------------------------------------------------------------

    /**
     * @var bool Whether Craft's own Find and Replace utility is hidden.
     *
     * On. Two find-and-replace tools side by side, one of which rewrites revisions and leaves the
     * search index stale with no way to tell, is worse than either on its own. The core utility is
     * only hidden, never removed: turning this off brings it straight back.
     */
    public bool $replaceCoreUtility = true;

    public string $defaultMode = 'plain';
    public bool $defaultCaseSensitive = true;

    /**
     * @var array[] Raw definitions of the non-element tables Scrub may rewrite.
     *              See {@see DatabaseTable}.
     */
    public array $tables = [];

    /** @var string */
    public string $logLevel = LogLevel::INFO;

    /**
     * @return DatabaseTable[]
     */
    public function databaseTables(): array
    {
        return array_map(
            static fn(array $table) => DatabaseTable::fromArray($table),
            array_values(array_filter($this->tables, static fn($table) => is_array($table) && ($table['table'] ?? '') !== '')),
        );
    }

    /**
     * Whether a field handle is off limits. Consulted by the scanner rather than by the form, so
     * that the console command and the queue job are bound by it too.
     */
    public function isProtectedField(string $handle): bool
    {
        return in_array($handle, $this->protectedFields, true);
    }

    protected function defineRules(): array
    {
        return [
            [['writeMode'], 'in', 'range' => [self::WRITE_SAFE, self::WRITE_FAST]],
            [['createRevisions', 'recordUndo', 'realtimeEnabled', 'defaultCaseSensitive', 'replaceCoreUtility'], 'boolean'],
            [['maxUnitsPerRun', 'maxUnitsInPreview', 'maxHitsPerUnit', 'queueThreshold', 'undoRetentionDays'], 'integer', 'min' => 0],
            [['maxUnitsInPreview', 'maxHitsPerUnit'], 'integer', 'min' => 1],
            [['protectedFields', 'protectedSources', 'tables'], 'safe'],
            [['logLevel'], 'in', 'range' => [
                LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR,
            ]],
        ];
    }
}
