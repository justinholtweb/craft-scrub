<?php

namespace justinholtweb\scrub\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\scrub\matching\Matcher;
use justinholtweb\scrub\models\Report;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\models\Run;
use justinholtweb\scrub\models\Scope;
use justinholtweb\scrub\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * `craft scrub/run/...`
 *
 * The console half exists for the two cases the control panel is bad at: a replacement that belongs
 * in a deployment, and a scheduled rule that belongs in cron.
 *
 * **Everything defaults to a dry run.** `--dry-run=0` is the only way to change anything, and it has
 * to be typed. A find-and-replace command that writes by default is one shell-history arrow key away
 * from being the worst thing that happened to a site that week.
 */
class RunController extends Controller
{
    public $defaultAction = 'replace';

    /** @var string What to find. */
    public string $find = '';

    /** @var string What to replace it with. Empty deletes the text. */
    public string $replace = '';

    /** @var string `plain`, `word` or `regex`. */
    public string $mode = Matcher::MODE_PLAIN;

    /** @var bool Whether the search is case sensitive. */
    public bool $caseSensitive = true;

    /** @var bool Whether the replacement copies the capitalisation of what it replaced. */
    public bool $preserveCase = false;

    /** @var string Comma-separated target handles: `content`, `slugs`, `assets`, `database`. */
    public string $targets = 'content';

    /** @var string Comma-separated source keys — `section:3,volume:1`. */
    public string $sources = '';

    /** @var string Comma-separated field handles. `title` and `slug` count. */
    public string $fields = '';

    /** @var string Comma-separated site IDs. */
    public string $sites = '';

    /** @var string Comma-separated element IDs. */
    public string $elements = '';

    /** @var bool Whether drafts are included. */
    public bool $drafts = false;

    /** @var bool Whether revisions are included. */
    public bool $revisions = false;

    /** @var bool Whether to only report what would change. */
    public bool $dryRun = true;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'replace' => array_merge($options, [
                'find', 'replace', 'mode', 'caseSensitive', 'preserveCase', 'targets',
                'sources', 'fields', 'sites', 'elements', 'drafts', 'revisions', 'dryRun',
            ]),
            'rule', 'due' => array_merge($options, ['dryRun']),
            default => $options,
        };
    }

    /**
     * Finds and replaces text.
     *
     * ```
     * craft scrub/run/replace --find="Acme Ltd" --replace="Acme Inc" --sources=section:3
     * craft scrub/run/replace --find="Acme Ltd" --replace="Acme Inc" --dry-run=0
     * ```
     */
    public function actionReplace(): int
    {
        if ($this->find === '') {
            $this->stderr("--find is required.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $rule = new Rule();
        $rule->find = $this->find;
        $rule->replace = $this->replace;
        $rule->mode = $this->mode;
        $rule->caseSensitive = $this->caseSensitive;
        $rule->preserveCase = $this->preserveCase;
        $rule->targets = $this->list($this->targets);

        $scope = new Scope();
        $scope->sourceKeys = $this->list($this->sources);
        $scope->fieldHandles = $this->list($this->fields);
        $scope->siteIds = array_map('intval', $this->list($this->sites));
        $scope->elementIds = array_map('intval', $this->list($this->elements));
        $scope->includeDrafts = $this->drafts;
        $scope->includeRevisions = $this->revisions;
        $rule->scope = $scope;

        return $this->execute($rule);
    }

    /**
     * Runs a saved rule by handle.
     *
     * ```
     * craft scrub/run/rule acme-rename --dry-run=0
     * ```
     */
    public function actionRule(string $handle): int
    {
        $rule = Plugin::getInstance()->rules->getByHandle($handle);

        if ($rule === null) {
            $this->stderr(sprintf("No rule with the handle “%s”.\n", $handle), Console::FG_RED);

            return ExitCode::DATAERR;
        }

        return $this->execute($rule);
    }

    /**
     * Runs every scheduled rule that is due. Belongs in cron.
     *
     * ```
     * * * * * * craft scrub/run/due --dry-run=0
     * ```
     */
    public function actionDue(): int
    {
        $plugin = Plugin::getInstance();
        $due = array_filter($plugin->rules->scheduled(), fn(Rule $rule) => $plugin->rules->isDue($rule));

        if ($due === []) {
            $this->stdout("Nothing due.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        $status = ExitCode::OK;

        foreach ($due as $rule) {
            $this->stdout(sprintf("%s\n", $rule->name), Console::FG_CYAN, Console::BOLD);

            if ($this->execute($rule, Run::SOURCE_SCHEDULE) !== ExitCode::OK) {
                $status = ExitCode::UNSPECIFIED_ERROR;
            }
        }

        return $status;
    }

    /**
     * Undoes a run.
     *
     * ```
     * craft scrub/run/revert 42
     * ```
     */
    public function actionRevert(int $id): int
    {
        $result = Plugin::getInstance()->runs->revert($id);

        foreach ($result['errors'] as $error) {
            $this->stderr($error . "\n", Console::FG_RED);
        }

        if ($result['reverted'] === 0 && $result['errors'] !== []) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf(
            "%d %s restored.\n",
            $result['reverted'],
            $result['reverted'] === 1 ? 'value' : 'values',
        ), Console::FG_GREEN);

        if ($result['conflicts'] > 0) {
            $this->stdout(sprintf(
                "%d %s left alone — %s been edited since the run.\n",
                $result['conflicts'],
                $result['conflicts'] === 1 ? 'was' : 'were',
                $result['conflicts'] === 1 ? "it's" : "they've",
            ), Console::FG_YELLOW);
        }

        if ($result['missing'] > 0) {
            $this->stdout(sprintf(
                "%d no longer %s.\n",
                $result['missing'],
                $result['missing'] === 1 ? 'exists' : 'exist',
            ), Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    /**
     * Lists the saved rules.
     */
    public function actionRules(): int
    {
        $rules = Plugin::getInstance()->rules->all();

        if ($rules === []) {
            $this->stdout("No saved rules.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        foreach ($rules as $rule) {
            $this->stdout(sprintf('%-24s', $rule->handle ?? '—'), Console::FG_CYAN);
            $this->stdout(sprintf('%-30s', $rule->name));
            $this->stdout(sprintf('%s → %s', $rule->find, $rule->replace ?: '(nothing)'), Console::FG_GREY);
            $this->stdout($rule->enabled ? "\n" : " [disabled]\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    // -----------------------------------------------------------------------------------------

    private function execute(Rule $rule, string $source = Run::SOURCE_CONSOLE): int
    {
        $plugin = Plugin::getInstance();

        // Previewed first, always — including on the way to a real run. It costs one scan and it is
        // what makes the numbers printed before the write the same numbers as the write.
        $report = $plugin->scrubber->preview($rule);

        $this->summarize($report);

        if ($report->errors !== []) {
            return ExitCode::DATAERR;
        }

        if ($this->dryRun) {
            $this->stdout("\nDry run — nothing was changed. Pass --dry-run=0 to write.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if ($report->hitCount === 0) {
            return ExitCode::OK;
        }

        $runId = $plugin->runs->start($rule, $source, false);

        try {
            $result = $plugin->scrubber->apply($rule, $runId);
            $plugin->runs->finish($runId, $result);

            if ($rule->id !== null) {
                $plugin->rules->markRun($rule);
            }
        } catch (Throwable $e) {
            $plugin->runs->fail($runId, $e->getMessage());
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf(
            "\n%d replacements written across %d places. Run #%d — undo with `craft scrub/run/revert %d`.\n",
            $result->hitCount,
            $result->changed,
            $runId,
            $runId,
        ), Console::FG_GREEN);

        if ($result->failed > 0) {
            $this->stdout(sprintf("%d failed. See the run for details.\n", $result->failed), Console::FG_RED);
        }

        return ExitCode::OK;
    }

    private function summarize(Report $report): void
    {
        foreach ($report->errors as $error) {
            $this->stderr($error . "\n", Console::FG_RED);
        }

        foreach ($report->warnings as $warning) {
            $this->stdout($warning . "\n", Console::FG_YELLOW);
        }

        if ($report->errors !== []) {
            return;
        }

        $this->stdout(sprintf(
            "%d matches in %d places (%d candidates scanned in %.2fs)\n",
            $report->hitCount,
            $report->unitCount,
            $report->scanned,
            $report->elapsed,
        ), Console::FG_GREEN);

        foreach ($report->units as $unit) {
            $this->stdout(sprintf("\n  %s", $unit->label()), Console::BOLD);
            $this->stdout(sprintf(" (%s%s)\n", $unit->kind(), $unit->siteName() ? ', ' . $unit->siteName() : ''), Console::FG_GREY);

            foreach ($unit->slots as $data) {
                $this->stdout(sprintf("    %s\n", $data['label']), Console::FG_CYAN);

                foreach ($data['hits'] as $hit) {
                    // Bracketed as well as coloured. Console output ends up in CI logs, in `tee`
                    // and in pasted terminal transcripts, none of which keep the colour — and
                    // without the brackets the old and new text run together into one word.
                    $this->stdout('      …' . $this->flatten($hit->before) . ' ');
                    $this->stdout('[-' . $this->flatten($hit->matched) . '-]', Console::FG_RED);
                    $this->stdout('{+' . $this->flatten($hit->replacement) . '+}', Console::FG_GREEN);
                    $this->stdout(' ' . $this->flatten($hit->after) . "…\n");
                }
            }
        }
    }

    /**
     * Context excerpts come out of prose, and prose contains newlines. One hit per line keeps the
     * output greppable.
     */
    private function flatten(string $text): string
    {
        return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    }

    /**
     * @return string[]
     */
    private function list(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn(string $item) => $item !== '',
        ));
    }
}
