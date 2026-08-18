<?php

namespace justinholtweb\scrub\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\events\ModelEvent;
use justinholtweb\scrub\matching\TextWalker;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\models\Scope;
use justinholtweb\scrub\Plugin;
use Throwable;
use yii\base\Event;

/**
 * Rules that run as content is saved, so new writing arrives already corrected.
 *
 * This is the "auto" half of the idea. A permanent replacement fixes what is already there; it does
 * nothing about the next person who types the old product name into a new entry next Tuesday. A rule
 * that runs on save closes that loop, and it is the difference between a one-off cleanup and a
 * standard that actually holds.
 *
 * It runs in `beforeSave`, so the corrected text is what gets written — there is no second save, no
 * revision churn, and nothing to undo separately. That also means these changes are **not** in the
 * run ledger: they aren't a run, they're part of somebody's own save, and the entry's revision
 * history already records them the ordinary way.
 */
class Autopilot extends Component
{
    public const CACHE_KEY = 'scrub:on-save-rules';

    /** @var Rule[]|null */
    private ?array $rules = null;

    /** Guards against a rule reacting to the save that Scrub itself is making. */
    private bool $applying = false;

    public function register(): void
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            return;
        }

        // Behind a cached list: this runs on every request on the site, and most of them have no
        // on-save rules to apply.
        if ($this->activeRules() === []) {
            return;
        }

        Event::on(
            Element::class,
            Element::EVENT_BEFORE_SAVE,
            function(ModelEvent $event) {
                /** @var ElementInterface $element */
                $element = $event->sender;

                $this->applyTo($element);
            }
        );
    }

    /**
     * The enabled on-save rules, cached.
     *
     * Not called `rules()` — see {@see Realtime::activeRules()} for why that name is taken.
     *
     * @return Rule[]
     */
    public function activeRules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $cache = Craft::$app->getCache();
        $cached = $cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $this->rules = array_map(static fn(array $rule) => Rule::fromArray($rule), $cached);
        }

        $rules = Plugin::getInstance()->rules->onSave();

        $cache->set(self::CACHE_KEY, array_map(static fn(Rule $rule) => $rule->toArray(), $rules));

        return $this->rules = $rules;
    }

    public function invalidate(): void
    {
        $this->rules = null;
        Craft::$app->getCache()->delete(self::CACHE_KEY);
    }

    /**
     * Applies every applicable on-save rule to an element in memory.
     *
     * @return int The number of replacements made.
     */
    public function applyTo(ElementInterface $element): int
    {
        // Revisions are records of what the content *was*. Correcting one falsifies history rather
        // than fixing anything, and it happens on every single save.
        if ($this->applying || $element->getIsRevision() || $element->propagating) {
            return 0;
        }

        $made = 0;
        $this->applying = true;

        try {
            foreach ($this->activeRules() as $rule) {
                $made += $this->applyRule($rule, $element);
            }
        } catch (Throwable $e) {
            // A broken rule must never stop somebody saving their work.
            Craft::warning(sprintf('On-save rule failed on element %s: %s', $element->id ?? 'new', $e->getMessage()), Plugin::LOG_CATEGORY);
        } finally {
            $this->applying = false;
        }

        return $made;
    }

    private function applyRule(Rule $rule, ElementInterface $element): int
    {
        $scope = $rule->scope;

        if (!in_array($element::class, $scope->elementTypes(), true) || !$scope->wantsElement($element)) {
            return 0;
        }

        $matcher = $rule->matcher();
        $hits = [];
        $made = 0;

        if ($element::hasTitles() && $scope->wantsSlot(Scope::SLOT_TITLE) && ($element->title ?? '') !== '') {
            $count = 0;
            $title = $matcher->replace((string)$element->title, $count);

            if ($count > 0) {
                $element->title = $title;
                $made += $count;
            }
        }

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$scope->wantsSlot($field->handle)) {
                continue;
            }

            try {
                $value = $field->serializeValue($element->getFieldValue($field->handle), $element);
            } catch (Throwable) {
                continue;
            }

            // An array from a container field is the nested entries' own content — they get saved
            // separately and this handler will see each of them. See ContentTarget for the long
            // version of why the test is the value's shape rather than the field's interface.
            if ($field instanceof ElementContainerFieldInterface && is_array($value)) {
                continue;
            }

            if (!is_string($value) && !is_array($value)) {
                continue;
            }

            $count = 0;
            $replaced = TextWalker::replace($value, $matcher, $hits, $count, 0);

            if ($count > 0) {
                $element->setFieldValue($field->handle, $replaced);
                $made += $count;
            }
        }

        return $made;
    }
}
