<?php

namespace justinholtweb\scrub\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\models\Scope;
use justinholtweb\scrub\records\RuleRecord;

/**
 * Saved rules.
 *
 * These live in the database, not in project config, and that is a deliberate choice rather than a
 * shortcut. A rule is closer to content than to configuration: it names a company that just changed
 * its name, a product that just got renamed, a phrase legal wants gone by Friday. Those are written
 * by the person who noticed, on the site where it matters, at the time it matters — and if they were
 * project config, that person would be told the control panel is read-only and to raise a pull
 * request.
 *
 * The cost is that rules don't deploy between environments on their own. Given the alternative, that
 * is the right trade.
 */
class Rules extends Component
{
    /** @var Rule[]|null */
    private ?array $rules = null;

    /**
     * @return Rule[]
     */
    public function all(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $records = RuleRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $this->rules = array_map(
            fn(RuleRecord $record) => $this->toModel($record),
            $records,
        );
    }

    /**
     * @return Rule[]
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->all(), static fn(Rule $rule) => $rule->enabled));
    }

    /**
     * Rules that rewrite rendered output.
     *
     * @return Rule[]
     */
    public function realtime(): array
    {
        return array_values(array_filter($this->enabled(), static fn(Rule $rule) => $rule->realtime));
    }

    /**
     * Rules that run as elements are saved.
     *
     * @return Rule[]
     */
    public function onSave(): array
    {
        return array_values(array_filter($this->enabled(), static fn(Rule $rule) => $rule->onSave));
    }

    /**
     * Rules with a cadence, whether or not they are due.
     *
     * @return Rule[]
     */
    public function scheduled(): array
    {
        return array_values(array_filter($this->enabled(), static fn(Rule $rule) => $rule->cadence !== null));
    }

    public function getById(int $id): ?Rule
    {
        foreach ($this->all() as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    public function getByHandle(string $handle): ?Rule
    {
        foreach ($this->all() as $rule) {
            if ($rule->handle === $handle) {
                return $rule;
            }
        }

        return null;
    }

    public function save(Rule $rule, bool $runValidation = true): bool
    {
        if ($runValidation && !$rule->validate()) {
            return false;
        }

        $record = $rule->id !== null ? RuleRecord::findOne($rule->id) : null;
        $record ??= new RuleRecord();

        $record->name = $rule->name ?: Craft::t('scrub', 'Untitled rule');
        $record->handle = $rule->handle ?: null;
        $record->enabled = $rule->enabled;
        $record->find = $rule->find;
        $record->replace = $rule->replace;
        $record->mode = $rule->mode;
        $record->caseSensitive = $rule->caseSensitive;
        $record->preserveCase = $rule->preserveCase;
        $record->multiline = $rule->multiline;
        $record->dotAll = $rule->dotAll;
        $record->targets = Json::encode(array_values($rule->targets));
        $record->scope = Json::encode($rule->scope->toArray());
        $record->realtime = $rule->realtime;
        $record->realtimeUris = Json::encode(array_values($rule->realtimeUris));
        $record->realtimeHtmlAware = $rule->realtimeHtmlAware;
        $record->onSave = $rule->onSave;
        $record->cadence = $rule->cadence;
        $record->sortOrder = $rule->sortOrder;

        if (!$record->save(false)) {
            return false;
        }

        $rule->id = (int)$record->id;
        $rule->uid = $record->uid;
        $this->forget();

        return true;
    }

    public function delete(int $id): bool
    {
        $record = RuleRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        $record->delete();
        $this->forget();

        return true;
    }

    /**
     * @param int[] $ids
     */
    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $position => $id) {
            Db::update('{{%scrub_rules}}', ['sortOrder' => $position + 1], ['id' => (int)$id]);
        }

        $this->forget();
    }

    /**
     * Drops every cached view of the rules.
     *
     * The real-time and on-save lists are cached across requests precisely so that a site with no
     * such rules pays nothing for the feature — which means a rule that has just been switched on
     * has to say so, or it appears not to work until the cache happens to expire.
     */
    private function forget(): void
    {
        $this->rules = null;

        $plugin = \justinholtweb\scrub\Plugin::getInstance();
        $plugin?->realtime->invalidate();
        $plugin?->autopilot->invalidate();
    }

    /**
     * Stamps a rule as having just run, for the schedule.
     */
    public function markRun(Rule $rule): void
    {
        if ($rule->id === null) {
            return;
        }

        Db::update('{{%scrub_rules}}', [
            'dateLastRun' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $rule->id]);

        $this->forget();
    }

    /**
     * Whether a scheduled rule is due.
     *
     * Deliberately generous about the boundary — "a day" means "more than 23 hours ago" — because a
     * cron running at 03:00 every day would otherwise skip a day whenever it started forty seconds
     * late.
     */
    public function isDue(Rule $rule, ?DateTime $now = null): bool
    {
        if ($rule->cadence === null || !$rule->enabled) {
            return false;
        }

        if ($rule->dateLastRun === null) {
            return true;
        }

        $now ??= new DateTime();
        $elapsed = $now->getTimestamp() - $rule->dateLastRun->getTimestamp();

        return $elapsed >= match ($rule->cadence) {
            Rule::CADENCE_DAILY => 23 * 3600,
            Rule::CADENCE_WEEKLY => 7 * 24 * 3600 - 3600,
            Rule::CADENCE_MONTHLY => 30 * 24 * 3600 - 3600,
            default => PHP_INT_MAX,
        };
    }

    private function toModel(RuleRecord $record): Rule
    {
        $rule = new Rule();

        $rule->id = (int)$record->id;
        $rule->uid = $record->uid;
        $rule->name = $record->name;
        $rule->handle = $record->handle;
        $rule->enabled = (bool)$record->enabled;
        $rule->find = (string)$record->find;
        $rule->replace = (string)$record->replace;
        $rule->mode = $record->mode;
        $rule->caseSensitive = (bool)$record->caseSensitive;
        $rule->preserveCase = (bool)$record->preserveCase;
        $rule->multiline = (bool)$record->multiline;
        $rule->dotAll = (bool)$record->dotAll;
        $rule->targets = self::decode($record->targets) ?: ['content'];
        $rule->scope = Scope::fromArray(self::decode($record->scope));
        $rule->realtime = (bool)$record->realtime;
        $rule->realtimeUris = self::decode($record->realtimeUris) ?: [];
        $rule->realtimeHtmlAware = (bool)$record->realtimeHtmlAware;
        $rule->onSave = (bool)$record->onSave;
        $rule->cadence = $record->cadence;
        $rule->dateLastRun = $record->dateLastRun !== null ? new DateTime($record->dateLastRun) : null;
        $rule->sortOrder = (int)$record->sortOrder;

        return $rule;
    }

    /**
     * MySQL returns JSON columns as strings, Postgres as arrays. Both arrive here.
     */
    private static function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = Json::decodeIfJson($value);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
