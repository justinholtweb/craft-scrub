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
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Saved rules (Pro).
 *
 * A saved rule is the same object as an ad-hoc replacement, with a name and somewhere to live. That
 * is what lets a rule be previewed on the Replace screen, run once from the console, applied on save
 * and used at render time without four descriptions of the same thing drifting apart.
 */
class RulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_RULES);

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException('Saved rules need Scrub Pro.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('scrub/rules/index', [
            'rules' => Plugin::getInstance()->rules->all(),
        ]);
    }

    public function actionEdit(?int $id = null, ?Rule $rule = null): Response
    {
        $plugin = Plugin::getInstance();

        // `$rule` arrives populated when a save failed validation, so the form comes back with the
        // user's own text and the errors against it rather than with whatever is in the database.
        if ($rule === null) {
            $rule = $id !== null ? $plugin->rules->getById($id) : new Rule();

            if ($rule === null) {
                throw new NotFoundHttpException('Rule not found.');
            }
        }

        return $this->renderTemplate('scrub/rules/edit', [
            'rule' => $rule,
            'isNew' => $rule->id === null,
            'settings' => $plugin->getSettings(),
            'targets' => $plugin->targets->available(),
            'sourceGroups' => Sources::grouped(),
            'fieldOptions' => Sources::fields(),
            'elementTypeOptions' => Sources::elementTypes(),
            'siteOptions' => Sources::sites(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $rule = Rule::fromArray($this->request->getBodyParam('rule', []));

        if (!$plugin->rules->save($rule)) {
            $this->setFailFlash(Craft::t('scrub', 'Couldn’t save the rule.'));

            // Routed back through `actionEdit` rather than redirected, so nothing the user typed is
            // lost on the way.
            Craft::$app->getUrlManager()->setRouteParams(['rule' => $rule]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('scrub', 'Rule saved.'));

        return $this->redirectToPostedUrl($rule);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int)$this->request->getRequiredBodyParam('id');

        Plugin::getInstance()->rules->delete($id);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess();
        }

        $this->setSuccessFlash(Craft::t('scrub', 'Rule deleted.'));

        return $this->redirect(UrlHelper::cpUrl('scrub/rules'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        Plugin::getInstance()->rules->reorder(
            $this->request->getRequiredBodyParam('ids'),
        );

        return $this->asSuccess();
    }

    /**
     * Runs a saved rule now.
     *
     * Always previewed first and always queued above the threshold — a saved rule gets no more
     * trust than one typed thirty seconds ago, because it was.
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REPLACE);

        $plugin = Plugin::getInstance();
        $rule = $plugin->rules->getById((int)$this->request->getRequiredBodyParam('id'));

        if ($rule === null) {
            throw new NotFoundHttpException('Rule not found.');
        }

        $report = $plugin->scrubber->preview($rule);

        if (!$report->canRun()) {
            $this->setFailFlash($report->errors[0] ?? Craft::t('scrub', 'Nothing matched, so nothing was changed.'));

            return $this->redirect(UrlHelper::cpUrl('scrub/rules'));
        }

        $threshold = $plugin->getSettings()->queueThreshold;
        $queued = $threshold > 0 && $report->unitCount >= $threshold;
        $runId = $plugin->runs->start($rule, $queued ? Run::SOURCE_QUEUE : Run::SOURCE_CP, false);

        if ($queued) {
            Queue::push(new ScrubJob(['rule' => $rule->toArray(), 'runId' => $runId]));
            $this->setSuccessFlash(Craft::t('scrub', '{n, plural, =1{One place} other{# places}} queued for replacement.', [
                'n' => $report->unitCount,
            ]));
        } else {
            $plugin->runs->finish($runId, $plugin->scrubber->apply($rule, $runId));
            $plugin->rules->markRun($rule);
            $this->setSuccessFlash(Craft::t('scrub', 'Rule run.'));
        }

        return $this->redirect(UrlHelper::cpUrl('scrub/runs/' . $runId));
    }
}
