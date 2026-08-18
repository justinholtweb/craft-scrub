<?php

namespace justinholtweb\scrub;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\utilities\FindAndReplace as CoreFindAndReplace;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\scrub\models\Settings;
use justinholtweb\scrub\services\Autopilot;
use justinholtweb\scrub\services\Realtime;
use justinholtweb\scrub\services\Rules;
use justinholtweb\scrub\services\Runs;
use justinholtweb\scrub\services\Scrubber;
use justinholtweb\scrub\services\Targets;
use justinholtweb\scrub\twig\Extension;
use justinholtweb\scrub\variables\ScrubVariable;
use yii\base\Event;

/**
 * Scrub — find and replace, done properly.
 *
 * Craft ships a find-and-replace utility. It selects every row of `elements_sites` whose title or
 * content is `LIKE` your search — every element type, every site, drafts and revisions included —
 * runs `str_replace` over it, and writes the result back with raw SQL. There is no preview, no
 * scope, no case handling, no word boundaries, no regular expressions, no undo, and no record that
 * it ever ran. Because it bypasses the element save, the search index is left describing content
 * that no longer exists and nothing tells you so.
 *
 * Every part of this plugin is an answer to one of those sentences.
 *
 * Scrub also does something core doesn't attempt: **real-time rules**, which rewrite rendered output
 * and never touch the database at all. A rename that isn't final, a term that's changing next
 * quarter, a phrase you want gone from the front end while the content is still being edited — those
 * are display problems, and solving a display problem by rewriting a hundred thousand rows is how
 * you end up needing an undo.
 *
 * @property-read Scrubber $scrubber
 * @property-read Targets $targets
 * @property-read Rules $rules
 * @property-read Runs $runs
 * @property-read Realtime $realtime
 * @property-read Autopilot $autopilot
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    /** See the Replace screen, preview, and read the history. */
    public const PERMISSION_VIEW = 'scrub:view';

    /** Actually write a replacement. Deliberately separate from previewing one. */
    public const PERMISSION_REPLACE = 'scrub:replace';

    /** Undo a run. */
    public const PERMISSION_REVERT = 'scrub:revert';

    /** Create and edit saved rules, including the ones that run automatically. */
    public const PERMISSION_RULES = 'scrub:rules';

    /** Rewrite rows in non-element tables. */
    public const PERMISSION_DATABASE = 'scrub:database';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'scrub';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'scrubber' => Scrubber::class,
                'targets' => Targets::class,
                'rules' => Rules::class,
                'runs' => Runs::class,
                'realtime' => Realtime::class,
                'autopilot' => Autopilot::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerCpUrlRules();
        $this->registerPermissions();
        $this->registerTwigVariable();
        $this->registerTwigExtension();
        $this->registerGarbageCollection();
        $this->registerCoreUtilityRedirect();

        // Both of these are Pro, both read saved rules, and both are cheap to skip: they attach no
        // listeners at all when there's nothing enabled for them to do.
        $this->realtime->register();
        $this->autopilot->register();
    }

    /**
     * Every edition check goes through here rather than calling `is()` directly, so the gate is one
     * method to find and one method to change.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    /**
     * Scrub keeps its own log.
     *
     * A tool that edits content in bulk needs an audit trail that survives the content. The run
     * ledger in the database is the convenient copy; `storage/logs/scrub.log` is the one that is
     * still there after somebody restores a backup to undo the thing the ledger recorded.
     */
    private function registerLogging(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $this->getSettings()->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 30,
        ]);
    }

    /**
     * Registers the `|scrub` filter.
     *
     * Guarded to web requests: registering a Twig extension boots the view, and a console command
     * that never renders anything has no reason to pay for it.
     */
    private function registerTwigExtension(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        Craft::$app->getView()->registerTwigExtension(new Extension());
    }

    /**
     * Exposes `craft.scrub` to templates — mostly for `|scrub`, which applies the real-time rules to
     * a string on demand.
     */
    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('scrub', ScrubVariable::class);
            }
        );
    }

    /**
     * Expires old undo records.
     *
     * Hooked to Craft's garbage collection rather than run on a schedule of its own, because the
     * whole point of the retention setting is that nobody has to remember it exists.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function() {
                $this->runs->collectGarbage();
            }
        );
    }

    /**
     * Points Craft's own Find and Replace utility at Scrub.
     *
     * The core utility stays where it is — removing something a site might depend on is not a
     * plugin's decision to make. But leaving two find-and-replace tools side by side, one of which
     * silently rewrites revisions and leaves the search index stale, is worse than either. So the
     * utility gets a note saying where the safer one is.
     */
    private function registerCoreUtilityRedirect(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function(RegisterComponentTypesEvent $event) {
                if (!$this->getSettings()->replaceCoreUtility) {
                    return;
                }

                $event->types = array_values(array_filter(
                    $event->types,
                    static fn(string $type) => $type !== CoreFindAndReplace::class,
                ));
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        $subnav = [
            'replace' => ['label' => Craft::t('scrub', 'Replace'), 'url' => 'scrub'],
        ];

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_RULES)) {
            $subnav['rules'] = ['label' => Craft::t('scrub', 'Rules'), 'url' => 'scrub/rules'];
        }

        $subnav['runs'] = ['label' => Craft::t('scrub', 'History'), 'url' => 'scrub/runs'];

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('scrub', 'Settings'), 'url' => 'scrub/settings'];
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('scrub/settings'));
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['scrub'] = 'scrub/replace/index';
                $event->rules['scrub/preview'] = 'scrub/replace/preview';
                $event->rules['scrub/rules'] = 'scrub/rules/index';
                $event->rules['scrub/rules/new'] = 'scrub/rules/edit';
                $event->rules['scrub/rules/<id:\d+>'] = 'scrub/rules/edit';
                $event->rules['scrub/runs'] = 'scrub/runs/index';
                $event->rules['scrub/runs/<id:\d+>'] = 'scrub/runs/detail';
                $event->rules['scrub/settings'] = 'scrub/settings/index';
                $event->rules['scrub/settings/tables'] = 'scrub/settings/tables';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('scrub', 'Scrub'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('scrub', 'Preview replacements and read the history'),
                            'nested' => [
                                self::PERMISSION_REPLACE => [
                                    'label' => Craft::t('scrub', 'Run replacements'),
                                    'nested' => [
                                        self::PERMISSION_DATABASE => [
                                            'label' => Craft::t('scrub', 'Rewrite non-element database tables'),
                                        ],
                                    ],
                                ],
                                self::PERMISSION_REVERT => [
                                    'label' => Craft::t('scrub', 'Undo a run'),
                                ],
                                self::PERMISSION_RULES => [
                                    'label' => Craft::t('scrub', 'Manage saved rules'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }
}
