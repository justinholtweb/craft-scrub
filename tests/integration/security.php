<?php
/**
 * Who can search and rewrite what — checked in the plugin-testing harness, mostly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-scrub/tests/integration/security.php
 *
 * Until the fix: a saved rule could be run, and saved, against raw database tables by somebody
 * without the database permission — only the Replace screen's run checked it — and the preview
 * showed matching snippets from database tables and from elements the viewer couldn't otherwise
 * read.
 *
 * Builds two throwaway sections and removes them (in a fresh process — see craft-nuke's
 * tests/README). Only those sections' entries are ever written.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\Plugin;
use justinholtweb\scrub\queue\ScrubJob;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$entries = Craft::$app->getEntries();
$run = bin2hex(random_bytes(3));
$token = "zqscrub$run";
$password = 'Scrub-' . bin2hex(random_bytes(6));
$cleanup = ['users' => [], 'rules' => []];

// Craft 5 derives "has a title" from the layout: without the title field, every entry's title is
// null and there'd be nothing to search.
$type = new EntryType(['name' => "Scrub Sec $run", 'handle' => "scrubSecType$run"]);
$layout = new craft\models\FieldLayout(['type' => Entry::class]);
$tab = new craft\models\FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
$tab->setElements([new craft\fieldlayoutelements\entries\EntryTitleField()]);
$layout->setTabs([$tab]);
$type->setFieldLayout($layout);
$entries->saveEntryType($type) or throw new RuntimeException(json_encode($type->getErrors()));

$sections = [];
$made = [];
foreach (['Open', 'Closed'] as $name) {
    $section = new Section(['name' => "Scrub $name $run", 'handle' => "scrub$name$run", 'type' => Section::TYPE_CHANNEL]);
    $siteSettings = [];
    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        $siteSettings[$site->id] = new Section_SiteSettings(['siteId' => $site->id, 'hasUrls' => false]);
    }
    $section->setSiteSettings($siteSettings);
    $section->setEntryTypes([$type]);
    $entries->saveSection($section) or throw new RuntimeException(json_encode($section->getErrors()));
    $sections[$name] = $entries->getSectionByHandle("scrub$name$run");

    $entry = new Entry(['sectionId' => $sections[$name]->id, 'typeId' => $type->id, 'title' => "$name note about $token"]);
    Craft::$app->getElements()->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
    $made[$name] = $entry;
}
Craft::$app->getProjectConfig()->saveModifiedConfigData();
foreach ($made as $entry) {
    str_contains((string)$entry->title, $token) or throw new RuntimeException('The fixture entries have no title to search.');
}

register_shutdown_function(function() use (&$cleanup, $sections, $type, $root, $run) {
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
    // By handle too: a refused save that wasn't refused (a broken guard) still leaves a row.
    Craft::$app->getDb()->createCommand()->delete('{{%scrub_rules}}', ['or', ['id' => $cleanup['rules']], ['like', 'handle', "%$run", false]])->execute();

    $restore = sys_get_temp_dir() . '/scrub-restore-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($restore, '<?php
require ' . var_export($root . '/bootstrap.php', true) . ';
$app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
$entries = Craft::$app->getEntries();
foreach (' . var_export(array_map(fn($s) => $s->handle, array_values($sections)), true) . ' as $handle) {
    if ($section = $entries->getSectionByHandle($handle)) {
        $entries->deleteSection($section);
    }
}
if ($type = $entries->getEntryTypeByHandle(' . var_export($type->handle, true) . ')) {
    $entries->deleteEntryType($type);
}
Craft::$app->getProjectConfig()->saveModifiedConfigData();
Craft::$app->getProjectConfig()->writeYamlFiles(true);
');
    exec('php ' . escapeshellarg($restore) . ' 2>&1', $out, $code);
    unlink($restore);
    $code === 0 or print("  ! Could not clean up: " . implode("\n", $out) . "\n");
});

$editor = new User(['username' => "scrub-editor-$run", 'email' => "scrub-editor-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($editor, false);
Craft::$app->getUsers()->activateUser($editor);
$openUid = $sections['Open']->uid;
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, [
    'accesscp', 'accessplugin-scrub', 'scrub:view', 'scrub:replace', 'scrub:rules',
    "viewentries:$openUid", "saveentries:$openUid", "viewpeerentries:$openUid", "savepeerentries:$openUid",
    // A multi-site harness: viewing any entry also needs the site.
    ...array_map(fn($site) => "editsite:$site->uid", Craft::$app->getSites()->getAllSites()),
]);
$cleanup['users'][] = $editor;

function client(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
    $http->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
        or throw new RuntimeException("Could not sign in as $username");

    return [$http, $csrf];
}

[$editorHttp, $editorCsrf] = client($editor->username, $password);
[$adminHttp, $adminCsrf] = client('admin', 'claudepassword');

$post = static fn(Client $http, callable $csrf, string $action, array $params, bool $json = true) => $http->post("index.php?p=admin/actions/$action", [
    'headers' => $json ? ['Accept' => 'application/json'] : [],
    'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
]);

$rule = static fn(array $targets, string $replace = 'cleaned') => [
    'find' => $token, 'replace' => $replace, 'mode' => 'plain', 'targets' => $targets,
];

$preview = static function(Client $http, callable $csrf, array $rule) use ($post): array {
    $response = $post($http, $csrf, 'scrub/replace/preview', ['rule' => $rule]);
    $body = json_decode((string)$response->getBody(), true) ?: [];

    return ['status' => $response->getStatusCode(), 'canRun' => $body['canRun'] ?? null, 'html' => (string)($body['html'] ?? '')];
};

$title = static fn(Entry $entry) => (string)(new Query())->select('title')->from('{{%elements_sites}}')
    ->where(['elementId' => $entry->id, 'siteId' => $entry->siteId])->scalar();

echo "\nDatabase tables\n";

check('without the database permission, a database preview is refused', function() use ($preview, $editorHttp, $editorCsrf, $rule) {
    $r = $preview($editorHttp, $editorCsrf, $rule(['database']));

    return $r['canRun'] === false && str_contains($r['html'], 'permission to search database tables') ?: json_encode(['status' => $r['status'], 'canRun' => $r['canRun']]);
});

check('an admin isn’t refused for that reason', function() use ($preview, $adminHttp, $adminCsrf, $rule) {
    $r = $preview($adminHttp, $adminCsrf, $rule(['database']));

    return !str_contains($r['html'], 'permission to search database tables') ?: 'refused';
});

check('“Manage rules” can’t save a database rule', function() use ($post, $editorHttp, $editorCsrf, $rule, $run) {
    $status = $post($editorHttp, $editorCsrf, 'scrub/rules/save', ['rule' => ['name' => "DB $run", 'handle' => "db$run"] + $rule(['database'])], false)->getStatusCode();
    $saved = (new Query())->from('{{%scrub_rules}}')->where(['handle' => "db$run"])->exists();

    return $status === 403 && !$saved ?: "status $status, saved " . var_export($saved, true);
});

$adminRule = new Rule(['name' => "Admin DB $run", 'handle' => "admindb$run", 'find' => $token, 'replace' => 'admin', 'targets' => ['database']]);
$plugin->rules->save($adminRule, false) or throw new RuntimeException(json_encode($adminRule->getErrors()));
$cleanup['rules'][] = $adminRule->id;

check('…or change the text of an admin’s, which a schedule would then run as the site', function() use ($post, $editorHttp, $editorCsrf, $adminRule, $run, $token) {
    $status = $post($editorHttp, $editorCsrf, 'scrub/rules/save', ['rule' => [
        'id' => $adminRule->id, 'name' => "Admin DB $run", 'handle' => "admindb$run", 'find' => $token, 'replace' => 'hijacked', 'targets' => ['content'],
    ]], false)->getStatusCode();
    $stored = Plugin::getInstance()->rules->getById($adminRule->id);

    return $status === 403 && $stored->replace === 'admin' && $stored->targets === ['database'] ?: "status $status, replace {$stored->replace}";
});

check('…or run it', function() use ($post, $editorHttp, $editorCsrf, $adminRule) {
    $status = $post($editorHttp, $editorCsrf, 'scrub/rules/run', ['id' => $adminRule->id], false)->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('a content rule saves fine', function() use ($post, $editorHttp, $editorCsrf, $rule, $run, &$cleanup) {
    $status = $post($editorHttp, $editorCsrf, 'scrub/rules/save', ['rule' => ['name' => "Content $run", 'handle' => "content$run"] + $rule(['content'])], false)->getStatusCode();
    $id = (new Query())->select('id')->from('{{%scrub_rules}}')->where(['handle' => "content$run"])->scalar();
    if ($id) {
        $cleanup['rules'][] = (int)$id;
    }

    return in_array($status, [200, 302], true) && $id ?: "status $status";
});

echo "\nElements the viewer can’t see\n";

check('the preview leaves them out, and says so', function() use ($preview, $editorHttp, $editorCsrf, $rule, $made) {
    $r = $preview($editorHttp, $editorCsrf, $rule(['content']));

    return str_contains($r['html'], $made['Open']->title) && !str_contains($r['html'], $made['Closed']->title) && str_contains($r['html'], 'left out')
        ?: json_encode(['open' => str_contains($r['html'], $made['Open']->title), 'closed' => str_contains($r['html'], $made['Closed']->title), 'note' => str_contains($r['html'], 'left out')]);
});

check('an admin sees both', function() use ($preview, $adminHttp, $adminCsrf, $rule, $made) {
    $r = $preview($adminHttp, $adminCsrf, $rule(['content']));

    return str_contains($r['html'], $made['Open']->title) && str_contains($r['html'], $made['Closed']->title) ?: 'missing one';
});

check('a run changes exactly what the preview showed', function() use ($post, $editorHttp, $editorCsrf, $rule, $made, $title, $token) {
    $post($editorHttp, $editorCsrf, 'scrub/replace/run', ['rule' => $rule(['content'], 'scrubbed')], false);

    return str_contains($title($made['Open']), 'scrubbed') && str_contains($title($made['Closed']), $token)
        ?: json_encode(['open' => $title($made['Open']), 'closed' => $title($made['Closed'])]);
});

check('…and so does a queued run, which carries who queued it', function() use ($plugin, $editor, $made, $title, $token) {
    // Put the open entry's token back, then run the queue job directly as the editor would have.
    $open = Entry::find()->id($made['Open']->id)->status(null)->one();
    $open->title = "Open note about $token";
    Craft::$app->getElements()->saveElement($open);

    $rule = new Rule(['find' => $token, 'replace' => 'queued', 'targets' => ['content']]);
    $runId = $plugin->runs->start($rule, \justinholtweb\scrub\models\Run::SOURCE_QUEUE, false);
    (new ScrubJob(['rule' => $rule->toArray(), 'runId' => $runId, 'userId' => $editor->id]))->execute(Craft::$app->getQueue());

    return str_contains($title($made['Open']), 'queued') && str_contains($title($made['Closed']), $token)
        ?: json_encode(['open' => $title($made['Open']), 'closed' => $title($made['Closed'])]);
});

check('a queued run whose user has gone fails rather than running as the site', function() use ($plugin, $made, $title, $token) {
    $rule = new Rule(['find' => $token, 'replace' => 'orphaned', 'targets' => ['content']]);
    $runId = $plugin->runs->start($rule, \justinholtweb\scrub\models\Run::SOURCE_QUEUE, false);
    (new ScrubJob(['rule' => $rule->toArray(), 'runId' => $runId, 'userId' => 999999999]))->execute(Craft::$app->getQueue());

    return str_contains($title($made['Closed']), $token) ?: 'the closed entry was changed';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
