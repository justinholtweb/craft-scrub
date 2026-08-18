<?php

namespace justinholtweb\scrub\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\scrub\helpers\Sources;
use justinholtweb\scrub\models\DatabaseTable;
use justinholtweb\scrub\Plugin;
use yii\web\Response;

/**
 * Settings. Admin only, like every other plugin settings screen.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('scrub/settings/index', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'isPro' => $plugin->isPro(),
            'fieldOptions' => Sources::fields(),
            'sourceGroups' => Sources::grouped(),
        ]);
    }

    public function actionTables(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('scrub/settings/tables', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'isPro' => $plugin->isPro(),
            'tables' => $plugin->getSettings()->databaseTables(),
            'suggestions' => DatabaseTable::suggestions(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $this->request->getBodyParam('settings', []);

        // Checkbox groups post an empty string in slot zero, which would otherwise become a
        // protected field handle that matches nothing.
        foreach (['protectedFields', 'protectedSources'] as $list) {
            $settings[$list] = array_values(array_filter(
                (array)($settings[$list] ?? []),
                static fn($value) => $value !== '' && $value !== null,
            ));
        }

        $settings['tables'] = array_values(array_filter(
            (array)($settings['tables'] ?? []),
            static fn($table) => is_array($table) && trim((string)($table['table'] ?? '')) !== '',
        ));

        $settings['tables'] = array_map(
            static fn(array $table) => DatabaseTable::fromArray($table)->toArray(),
            $settings['tables'],
        );

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            $this->setFailFlash(Craft::t('scrub', 'Couldn’t save settings.'));

            return null;
        }

        // A table added here changes which targets exist, and a rule cache built a minute ago
        // doesn't know that.
        $plugin->targets->reset();
        $plugin->realtime->invalidate();
        $plugin->autopilot->invalidate();

        $this->setSuccessFlash(Craft::t('app', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
