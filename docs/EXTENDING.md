# Extending Scrub

Scrub's scanner knows nothing about entries, assets or database tables. It asks each *target* for
*units*, runs the matcher over their values, and hands the changed values back to the same target to
write. That is the whole extension point: somewhere else that holds text implements three methods
and gets preview, undo, history, the console command and the run ledger for free.

## Adding a target

A target is anything that can enumerate string-bearing things and write them back.

```php
use craft\db\Query;
use craft\helpers\Db;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\targets\BaseTarget;
use justinholtweb\scrub\targets\Unit;

class ArticleTarget extends BaseTarget
{
    public static function handle(): string
    {
        return 'knowledge-base';
    }

    public function label(): string
    {
        return 'Knowledge base articles';
    }

    public function description(): string
    {
        return 'Article bodies and summaries.';
    }

    /**
     * Return a Generator: a scan of a large site holds one unit in memory at a time, and the
     * caller may stop early.
     */
    public function units(Rule $rule): iterable
    {
        $rows = (new Query())
            ->select(['id', 'title', 'body', 'summary'])
            ->from('{{%kb_articles}}')
            // Narrow it yourself if you can. Scrub will still check every string it is handed, so
            // a filter here only ever has to be a *superset* of what really matches.
            ->where(['like', 'body', $rule->find])
            ->each();

        foreach ($rows as $row) {
            yield $this->unitFor($row);
        }
    }

    /**
     * Re-resolve one unit from its ref. Called by undo, possibly weeks later, so it has to cope
     * with the thing having been deleted — return null rather than throwing.
     */
    public function unit(string $ref): ?Unit
    {
        $row = (new Query())->from('{{%kb_articles}}')->where(['id' => $ref])->one();

        return $row !== null ? $this->unitFor($row) : null;
    }

    public function write(Unit $unit, array $values): void
    {
        Db::update('{{%kb_articles}}', $values, ['id' => $unit->ref]);
    }

    private function unitFor(array $row): Unit
    {
        return new Unit(
            target: self::handle(),
            ref: (string)$row['id'],
            kind: 'Article',
            label: $row['title'],
            cpUrl: "kb/articles/{$row['id']}",
            values: ['body' => $row['body'], 'summary' => $row['summary']],
            slotLabels: ['body' => 'Body', 'summary' => 'Summary'],
        );
    }
}
```

Register it:

```php
use justinholtweb\scrub\events\RegisterTargetsEvent;
use justinholtweb\scrub\services\Targets;
use yii\base\Event;

Event::on(Targets::class, Targets::EVENT_REGISTER_TARGETS, function(RegisterTargetsEvent $event) {
    $event->targets[ArticleTarget::handle()] = new ArticleTarget();
});
```

### Things worth knowing

**A `ref` is an identity, not a pointer.** It is written to the undo ledger and read back later, so
it must be resolvable by `unit()` on its own. An offset into a result set is not one.

**Values may be arrays.** Scrub walks nested arrays and replaces every string it finds, so a value
that is a list of rows of cells works without any effort from you. Anything that isn't a string or
an array is left alone, which is why relation fields — lists of integers — are skipped for free.

**Only changed slots are passed to `write()`.** Don't assume the array contains every slot the unit
offered.

**Throw from `write()` to report a failure.** The unit is marked failed in the report and the run
carries on with the rest, which is almost always better than aborting.

**`edition()` gates the target.** Return `Plugin::EDITION_PRO` to hide it from Lite installs.
`isSupported()` hides it when the thing it reads isn't there — an uninstalled plugin, a missing
table. An unsupported target is hidden rather than shown broken.

## Cancelling and adjusting a run

`Scrubber::EVENT_BEFORE_SCRUB` fires once, before anything is read, and is cancellable:

```php
use justinholtweb\scrub\events\ScrubEvent;
use justinholtweb\scrub\services\Scrubber;

Event::on(Scrubber::class, Scrubber::EVENT_BEFORE_SCRUB, function(ScrubEvent $event) {
    if (!$event->dryRun && date('N') > 5) {
        $event->isValid = false;
    }
});
```

`Scrubber::EVENT_BEFORE_WRITE_UNIT` fires per unit, is cancellable per unit, and lets you edit the
new values in place — the escape hatch for "replace it, but not in this one field of this one
section", which is a reasonable thing to want and a terrible thing to express as a scope.

```php
use justinholtweb\scrub\events\UnitEvent;

Event::on(Scrubber::class, Scrubber::EVENT_BEFORE_WRITE_UNIT, function(UnitEvent $event) {
    unset($event->values['legalDisclaimer']);

    if ($event->values === []) {
        $event->isValid = false;
    }
});
```

`Scrubber::EVENT_AFTER_SCRUB` fires once at the end, dry run or not, with the finished report.

## Applying rules yourself

```php
use justinholtweb\scrub\Plugin;

// The real-time rules, applied to a string.
$text = Plugin::getInstance()->realtime->apply($text);

// One named rule.
$text = Plugin::getInstance()->rules->getByHandle('house-style')?->matcher()->replace($text) ?? $text;
```

In Twig: `{{ text|scrub }}` and `{{ text|scrub('house-style') }}`.

## Using the matcher on its own

`justinholtweb\scrub\matching\Matcher` has no Craft dependency and is safe to use anywhere:

```php
use justinholtweb\scrub\matching\Matcher;

$matcher = Matcher::compile(
    Matcher::MODE_WORD,
    'widget',
    'gadget',
    caseSensitive: false,
    preserveCase: true,
);

$matcher->replace('Widget');        // "Gadget"
$matcher->count($haystack);         // how many
$matcher->hits($haystack, limit: 5) // matches with surrounding context
```

`Matcher::explain()` returns a readable error for a broken pattern, or null — which is what the rule
form uses to complain about a regular expression while you're typing it rather than twenty seconds
into a queue job.
