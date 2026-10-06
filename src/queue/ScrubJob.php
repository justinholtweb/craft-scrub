<?php

namespace justinholtweb\scrub\queue;

use Craft;
use craft\elements\User;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\models\Run;
use justinholtweb\scrub\Plugin;
use Throwable;

/**
 * Runs a replacement in the background.
 *
 * Used above the queue threshold, where doing the work inside the request would mean a page that
 * loads for four minutes and a PHP timeout somewhere in the middle of a partially-applied run.
 *
 * The rule is carried as an array rather than as a model. Queue jobs are serialized to the database
 * and may be executed by a worker running a different version of the plugin than the one that
 * queued them; an array survives that, and a model with a new required property does not.
 */
class ScrubJob extends BaseJob
{
    /** @var array The rule, as `Rule::toArray()`. */
    public array $rule = [];

    /** @var int The ledger row already opened for this run. */
    public int $runId = 0;

    /**
     * @var int|null Who queued it from the control panel. The run leaves out what they can't view,
     * as their preview did. Null for the console and for scheduled rules, which act as the site.
     */
    public ?int $userId = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $rule = Rule::fromArray($this->rule);

        $viewer = null;
        if ($this->userId !== null) {
            $viewer = User::find()->id($this->userId)->status(null)->one();

            // Never fall back to running as the site: that would change what they couldn't see.
            if ($viewer === null) {
                $plugin->runs->fail($this->runId, Craft::t('scrub', 'The user who queued this run no longer exists.'));

                return;
            }
        }

        try {
            $report = $plugin->scrubber->apply($rule, $this->runId, function(int $done) use ($queue) {
                // The total isn't knowable without scanning twice, so progress is reported as a
                // count rather than a fraction — an honest "1,240 places changed" beats a
                // progress bar that lies.
                $this->setProgress($queue, 0, Craft::t('scrub', '{n, plural, =1{One place} other{# places}} changed', [
                    'n' => $done,
                ]));
            }, $viewer);

            $plugin->runs->finish($this->runId, $report);

            if ($rule->id !== null) {
                $plugin->rules->markRun($rule);
            }
        } catch (Throwable $e) {
            $plugin->runs->fail($this->runId, $e->getMessage());

            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        $rule = Rule::fromArray($this->rule);

        return Translation::prep('scrub', 'Replacing “{find}” with “{replace}”', [
            'find' => $rule->find,
            'replace' => $rule->replace,
        ]);
    }
}
