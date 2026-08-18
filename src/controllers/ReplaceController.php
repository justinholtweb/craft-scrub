<?php

namespace justinholtweb\scrub\controllers;

use Craft;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\scrub\helpers\Sources;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\models\Run;
use justinholtweb\scrub\Plugin;
use justinholtweb\scrub\queue\ScrubJob;
use Throwable;
use yii\web\Response;

/**
 * The Replace screen.
 *
 * Three actions, and the order they run in is the guardrail: build a rule, preview it, and only then
 * run it. `actionRun()` previews again, server-side, rather than trusting the numbers the browser was
 * shown — the form may have been open for an hour, somebody may have published four entries since,
 * and the check is one scan through the same code path the run itself will take.
 */
class ReplaceController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $rule = new Rule();
        $rule->mode = $settings->defaultMode;
        $rule->caseSensitive = $settings->defaultCaseSensitive;

        // A saved rule can be loaded into the ad-hoc form and run once, which is how "run this rule
        // now" works without a second screen that does almost the same thing.
        $ruleId = $this->request->getQueryParam('rule');

        if ($ruleId !== null) {
            $rule = $plugin->rules->getById((int)$ruleId) ?? $rule;
        }

        return $this->renderTemplate('scrub/replace/index', [
            'rule' => $rule,
            'settings' => $settings,
            'isPro' => $plugin->isPro(),
            'targets' => $plugin->targets->available(),
            'sourceGroups' => Sources::grouped(),
            'fieldOptions' => Sources::fields(),
            'elementTypeOptions' => Sources::elementTypes(),
            'siteOptions' => Sources::sites(),
            'canReplace' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_REPLACE),
        ]);
    }

    /**
     * Returns the preview panel, as JSON, for the form.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $rule = $this->rule();
        $report = Plugin::getInstance()->scrubber->preview($rule);

        return $this->asJson([
            'html' => $this->getView()->renderTemplate('scrub/replace/_report', [
                'report' => $report,
                'rule' => $rule,
            ], $this->getView()::TEMPLATE_MODE_CP),
            'canRun' => $report->canRun(),
            'hits' => $report->hitCount,
            'units' => $report->unitCount,
            'queued' => $this->shouldQueue($report->unitCount),
        ]);
    }

    /**
     * Runs it.
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REPLACE);

        $plugin = Plugin::getInstance();
        $rule = $this->rule();

        if (in_array('database', $rule->targets, true)) {
            $this->requirePermission(Plugin::PERMISSION_DATABASE);
        }

        // Previewed again here, server-side. The browser's copy is a suggestion; this is the check,
        // and it runs the same code the replacement will.
        $report = $plugin->scrubber->preview($rule);

        if (!$report->canRun()) {
            $this->setFailFlash($report->errors[0] ?? Craft::t('scrub', 'Nothing matched, so nothing was changed.'));

            return $this->redirectToPostedUrl();
        }

        $queued = $this->shouldQueue($report->unitCount);
        $runId = $plugin->runs->start($rule, $queued ? Run::SOURCE_QUEUE : Run::SOURCE_CP, false);

        if ($queued) {
            Queue::push(new ScrubJob(['rule' => $rule->toArray(), 'runId' => $runId]));

            $this->setSuccessFlash(Craft::t('scrub', '{n, plural, =1{One place} other{# places}} queued for replacement.', [
                'n' => $report->unitCount,
            ]));

            return $this->redirect(UrlHelper::cpUrl('scrub/runs/' . $runId));
        }

        try {
            $result = $plugin->scrubber->apply($rule, $runId);
            $plugin->runs->finish($runId, $result);
        } catch (Throwable $e) {
            $plugin->runs->fail($runId, $e->getMessage());
            $this->setFailFlash($e->getMessage());

            return $this->redirect(UrlHelper::cpUrl('scrub/runs/' . $runId));
        }

        $this->setSuccessFlash(Craft::t('scrub', '{hits, plural, =1{One replacement} other{# replacements}} made in {units, plural, =1{one place} other{# places}}.', [
            'hits' => $result->hitCount,
            'units' => $result->changed,
        ]));

        return $this->redirect(UrlHelper::cpUrl('scrub/runs/' . $runId));
    }

    /**
     * Whether a run of this size belongs in the queue rather than in the request.
     */
    private function shouldQueue(int $units): bool
    {
        $threshold = Plugin::getInstance()->getSettings()->queueThreshold;

        return $threshold > 0 && $units >= $threshold;
    }

    private function rule(): Rule
    {
        return Rule::fromArray($this->request->getBodyParam('rule', []));
    }
}
