<?php

namespace justinholtweb\scrub\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\scrub\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The history, and the undo.
 */
class RunsController extends Controller
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
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $perPage = 50;

        return $this->renderTemplate('scrub/runs/index', [
            'runs' => $plugin->runs->all($perPage, ($page - 1) * $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $plugin->runs->total(),
            'canRevert' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_REVERT),
        ]);
    }

    public function actionDetail(int $id): Response
    {
        $plugin = Plugin::getInstance();
        $run = $plugin->runs->get($id);

        if ($run === null) {
            throw new NotFoundHttpException('Run not found.');
        }

        return $this->renderTemplate('scrub/runs/detail', [
            'run' => $run,
            'outstanding' => $plugin->runs->changeCount($id),
            'canRevert' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_REVERT),
        ]);
    }

    public function actionRevert(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REVERT);

        $id = (int)$this->request->getRequiredBodyParam('id');
        $result = Plugin::getInstance()->runs->revert($id);

        if ($result['errors'] !== [] && $result['reverted'] === 0) {
            $this->setFailFlash($result['errors'][0]);

            return $this->redirect(UrlHelper::cpUrl('scrub/runs/' . $id));
        }

        // The conflicts are the interesting number, not the successes: they are the values somebody
        // has edited since, which the undo deliberately left alone.
        $message = Craft::t('scrub', '{n, plural, =1{One value} other{# values}} restored.', [
            'n' => $result['reverted'],
        ]);

        if ($result['conflicts'] > 0) {
            $message .= ' ' . Craft::t('scrub', '{n, plural, =1{One was} other{# were}} left alone — they’ve been edited since.', [
                'n' => $result['conflicts'],
            ]);
        }

        if ($result['missing'] > 0) {
            $message .= ' ' . Craft::t('scrub', '{n, plural, =1{One no longer exists} other{# no longer exist}}.', [
                'n' => $result['missing'],
            ]);
        }

        $this->setSuccessFlash($message);

        return $this->redirect(UrlHelper::cpUrl('scrub/runs/' . ($result['runId'] ?? $id)));
    }
}
