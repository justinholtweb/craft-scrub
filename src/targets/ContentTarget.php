<?php

namespace justinholtweb\scrub\targets;

use Craft;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Json;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\models\Scope;
use justinholtweb\scrub\Plugin;
use Throwable;
use yii\db\Expression;

/**
 * Titles and custom field content, for every element type on the site.
 *
 * This is the target that stands in for Craft's own utility, and most of what is interesting about
 * it is what it refuses to do.
 *
 * **It doesn't parse field values.** Rather than teach Scrub about each field type — and be wrong
 * about the next one somebody installs — it walks the *serialized* value and replaces every string
 * it finds. A plain text field is a string; a table field is rows of cells; a relation field is a
 * list of integers and is therefore skipped without a line of code being written about it.
 *
 * **It doesn't touch nested elements from the outside.** Matrix and content-block fields serialize
 * their entries' entire content into the owner's value. Walking that would count every match twice
 * and write the same text through two different paths. Those fields are skipped here; their entries
 * are elements in their own right and come round on their own.
 *
 * **It doesn't write raw SQL** — unless asked to. Core writes with `Db::update()`, which skips the
 * element save entirely: no search index update, no cache invalidation, no `EVENT_AFTER_SAVE_ELEMENT`,
 * no revision. Search results silently go stale and nothing says so. Here that is the opt-in fast
 * path, for sites where the safe one would take hours, and it queues the re-index that core forgets.
 */
class ContentTarget extends ElementTarget
{
    public static function handle(): string
    {
        return 'content';
    }

    public function label(): string
    {
        return Craft::t('scrub', 'Titles and field content');
    }

    public function description(): string
    {
        return Craft::t('scrub', 'Element titles and every custom field on them — entries, categories, tags, assets, users and globals.');
    }

    protected function slotsFor(ElementInterface $element, Scope $scope): array
    {
        $values = [];
        $labels = [];

        if ($element::hasTitles() && $scope->wantsSlot(Scope::SLOT_TITLE) && $element->title !== null) {
            $values[Scope::SLOT_TITLE] = (string)$element->title;
            $labels[Scope::SLOT_TITLE] = Craft::t('app', 'Title');
        }

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$scope->wantsSlot($field->handle)) {
                continue;
            }

            try {
                $value = $field->serializeValue($element->getFieldValue($field->handle), $element);
            } catch (Throwable $e) {
                // A field whose value can't be read — a broken plugin, a missing volume — must not
                // take the whole scan down with it.
                Craft::warning(sprintf(
                    'Skipped %s on element %s: %s',
                    $field->handle,
                    $element->id,
                    $e->getMessage(),
                ), Plugin::LOG_CATEGORY);

                continue;
            }

            if (self::duplicatesNestedContent($field, $value) || (!is_string($value) && !is_array($value))) {
                continue;
            }

            $values[$field->handle] = $value;
            $labels[$field->handle] = $field->name;
        }

        return [$values, $labels];
    }


    /**
     * Whether a field's serialized value contains the *content* of nested elements rather than
     * references to them.
     *
     * This distinction cost an afternoon, so it's worth writing down. Both Matrix and CKEditor are
     * `ElementContainerFieldInterface` — they both own nested entries — but they serialize
     * completely differently:
     *
     * - **Matrix** returns an array holding every nested entry's title, slug and full field values.
     *   Walking that would count every match twice (once here, once when the nested entry comes
     *   round as an element in its own right) and would write the same text back through two
     *   different paths.
     * - **CKEditor** returns the HTML string, in which a nested entry is a `<craft-entry>`
     *   *reference*. Walking that is not only safe, it is essential — a rich text field is the most
     *   important field on the site to be able to search, and skipping every container field on
     *   principle silently skips it.
     *
     * So the test is the shape of the value, not the interface: a string can only hold a reference,
     * an array can hold the content.
     */
    private static function duplicatesNestedContent(FieldInterface $field, mixed $value): bool
    {
        return $field instanceof ElementContainerFieldInterface && is_array($value);
    }

    protected function applyValues(ElementInterface $element, array $values): void
    {
        foreach ($values as $slot => $value) {
            if ($slot === Scope::SLOT_TITLE) {
                $element->title = (string)$value;
            } else {
                $element->setFieldValue($slot, $value);
            }
        }
    }

    public function write(Unit $unit, array $values): void
    {
        if (Plugin::getInstance()->getSettings()->writeMode !== \justinholtweb\scrub\models\Settings::WRITE_FAST) {
            parent::write($unit, $values);
            return;
        }

        /** @var ElementInterface|null $element */
        $element = $unit->subject ?? $this->unit($unit->ref)?->subject;

        if ($element === null) {
            throw new \RuntimeException(sprintf('Element %s no longer exists.', $unit->ref));
        }

        $this->writeRaw($element, $values);
    }

    protected function applyPrefilter(Query $query, Rule $rule): void
    {
        $needle = $this->needle($rule);

        if ($needle === null) {
            return;
        }

        $db = Craft::$app->getDb();

        $content = $db->getIsPgsql()
            ? new Expression('CAST(' . $db->quoteColumnName('elements_sites.content') . ' AS TEXT)')
            : 'elements_sites.content';

        $where = ['or', $this->like('elements_sites.title', $needle)];

        foreach ($this->jsonForms($needle) as $form) {
            $where[] = $this->like($content, $form);
        }

        $query->andWhere($where);
    }

    /**
     * The opt-in fast path: write the content JSON straight back, the way Craft's own utility does,
     * and then queue the search re-index that Craft's own utility forgets.
     *
     * Worth having, because the safe path is a full element save per element per site and on a
     * 200,000-entry site that is measured in hours. Worth being opt-in, because everything a save
     * does — URI regeneration, nested element propagation, `afterSave` in every plugin installed —
     * does not happen here.
     *
     * @param array<string, mixed> $values
     */
    private function writeRaw(ElementInterface $element, array $values): void
    {
        $layout = $element->getFieldLayout();

        $content = (new Query())
            ->select(['content'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $element->id, 'siteId' => $element->siteId])
            ->scalar();

        $content = is_string($content) && $content !== '' ? Json::decode($content) : [];
        $columns = [];

        foreach ($values as $slot => $value) {
            if ($slot === Scope::SLOT_TITLE) {
                $columns['title'] = (string)$value;
                continue;
            }

            // Content is keyed by *field layout element* UID — not by handle, and not by field UID.
            // A field that isn't on this element's layout has nowhere to go, which can only happen
            // if the layout changed between the preview and the write.
            $uid = $layout?->getFieldByHandle($slot)?->layoutElement->uid ?? null;

            if ($uid !== null) {
                $content[$uid] = $value;
            }
        }

        $columns['content'] = Json::encode($content);

        Craft::$app->getDb()->createCommand()
            ->update(Table::ELEMENTS_SITES, $columns, [
                'elementId' => $element->id,
                'siteId' => $element->siteId,
            ])
            ->execute();

        Craft::$app->getSearch()->indexElementAttributes($element);
    }
}
