<?php

namespace justinholtweb\scrub\targets;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use Generator;
use justinholtweb\scrub\matching\Literal;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\models\Scope;
use justinholtweb\scrub\Plugin;
use RuntimeException;
use yii\db\Expression;

/**
 * Shared machinery for every target that reads and writes elements.
 *
 * All three of the built-in element targets — field content, slugs, asset metadata — ask the same
 * question of the database (*which element rows could possibly contain this?*) and differ only in
 * which columns they prefilter on, which slots they expose, and how a changed slot is written back.
 *
 * The discovery here is the part core's find-and-replace gets wrong, and it's worth being explicit
 * about why this is safe. The `LIKE` is a **prefilter**: its job is to turn a hundred thousand rows
 * into a few dozen candidates, and it is allowed to return rows that don't really match. What it is
 * never allowed to do is *miss* one — so it is case-insensitive whatever the rule says, it searches
 * for both the raw and the JSON-escaped form of the needle, and it is dropped entirely whenever a
 * regular expression can't be proven to require a literal. Missing rows would make the preview a
 * lie, and the preview is the product.
 */
abstract class ElementTarget extends BaseTarget
{
    /** Rows per batch. Each becomes a loaded element, so this is a memory ceiling too. */
    protected const BATCH = 100;

    /**
     * The slots this target exposes on a given element, as `[values, labels]`.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    abstract protected function slotsFor(ElementInterface $element, Scope $scope): array;

    /**
     * Puts new slot values onto the element, ready to be saved.
     *
     * @param array<string, mixed> $values
     */
    abstract protected function applyValues(ElementInterface $element, array $values): void;

    /**
     * Narrows the candidate query to rows that could contain the needle.
     *
     * Called with the rule so an implementation can decide there is no usable prefilter and leave
     * the query alone, which means "read everything in scope".
     */
    abstract protected function applyPrefilter(Query $query, Rule $rule): void;

    /**
     * Element classes this target can handle. Intersected with the scope's own list.
     *
     * @return string[]
     */
    protected function elementTypes(): array
    {
        return Scope::supportedElementTypes();
    }

    /**
     * @return Generator<Unit>
     */
    public function units(Rule $rule): Generator
    {
        $scope = $rule->scope;
        $elements = Craft::$app->getElements();
        $siteNames = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteNames[$site->id] = $site->name;
        }

        foreach ($this->rows($rule) as $row) {
            $element = $elements->getElementById(
                (int)$row['elementId'],
                $row['type'],
                (int)$row['siteId'],
                // `getElementById()` already lets drafts and revisions through; the trash is the one
                // thing it filters, and only asking for it when the scope wants it keeps the default
                // honest.
                $scope->includeTrashed ? ['trashed' => null] : [],
            );

            if ($element === null || !$scope->wantsElement($element)) {
                continue;
            }

            $unit = $this->unitFor($element, $siteNames[$element->siteId] ?? null, $scope);

            if ($unit !== null) {
                yield $unit;
            }
        }
    }

    public function unit(string $ref): ?Unit
    {
        [$elementId, $siteId] = array_pad(explode(':', $ref), 2, null);

        if (!$elementId || !$siteId) {
            return null;
        }

        $element = Craft::$app->getElements()->getElementById((int)$elementId, null, (int)$siteId, ['trashed' => null]);

        if ($element === null) {
            return null;
        }

        return $this->unitFor($element, Craft::$app->getSites()->getSiteById((int)$siteId)?->name, new Scope());
    }

    public function write(Unit $unit, array $values): void
    {
        /** @var ElementInterface|null $element */
        $element = $unit->subject ?? $this->unit($unit->ref)?->subject;

        if ($element === null) {
            throw new RuntimeException(sprintf('Element %s no longer exists.', $unit->ref));
        }

        $this->applyValues($element, $values);
        $this->save($element, $unit->ref);
    }

    // -----------------------------------------------------------------------------------------
    // Shared internals
    // -----------------------------------------------------------------------------------------

    /**
     * Saves the element the careful way.
     *
     * Validation off, propagation off, and both deliberately.
     *
     * Validation, because an entry that has been in the database for four years may not satisfy a
     * field that was made required last month — and refusing to fix a typo in it is no service to
     * anybody. Craft still keeps URIs unique on save, which is the one uniqueness constraint a
     * replacement can realistically break.
     *
     * Propagation, because every site's row is scanned and replaced separately. Propagating would
     * copy this site's text over the *translated* copy in the next one.
     */
    protected function save(ElementInterface $element, string $ref): void
    {
        $settings = Plugin::getInstance()->getSettings();

        // `resaving` suppresses the revision an entry save would otherwise create. Left off by
        // default: a revision is how an editor sees, in the interface they already use, that their
        // page changed and what it changed from.
        $element->resaving = !$settings->createRevisions;

        if (!Craft::$app->getElements()->saveElement($element, false, false, true)) {
            throw new RuntimeException(sprintf(
                'Craft refused to save %s: %s',
                $ref,
                implode('; ', array_map(
                    static fn(array $errors) => implode(', ', $errors),
                    $element->getErrors(),
                )) ?: 'no reason given',
            ));
        }
    }

    protected function unitFor(ElementInterface $element, ?string $siteName, Scope $scope): ?Unit
    {
        [$values, $labels] = $this->slotsFor($element, $scope);

        if ($values === []) {
            return null;
        }

        return new Unit(
            target: static::handle(),
            ref: $element->id . ':' . $element->siteId,
            kind: $element::displayName(),
            label: $this->labelFor($element),
            cpUrl: $element->getCpEditUrl(),
            values: $values,
            slotLabels: $labels,
            subject: $element,
            siteName: $siteName,
        );
    }

    protected function labelFor(ElementInterface $element): string
    {
        $label = $element instanceof Asset ? $element->filename : (string)$element;

        if ($element->getIsDraft()) {
            $label .= ' ' . Craft::t('scrub', '(draft)');
        } elseif ($element->getIsRevision()) {
            $label .= ' ' . Craft::t('scrub', '(revision)');
        }

        return $label !== '' ? $label : sprintf('#%s', $element->id);
    }

    /**
     * Candidate rows, batched.
     *
     * @return Generator<array{elementId: int, siteId: int, type: string}>
     */
    protected function rows(Rule $rule): Generator
    {
        $scope = $rule->scope;
        $types = array_values(array_intersect($scope->elementTypes(), $this->elementTypes()));

        if ($types === []) {
            return;
        }

        $query = (new Query())
            ->select(['elements_sites.elementId', 'elements_sites.siteId', 'elements.type'])
            ->from(['elements_sites' => Table::ELEMENTS_SITES])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[elements_sites.elementId]]')
            ->where(['elements.type' => $types])
            ->andWhere(['elements_sites.siteId' => $scope->siteIds()])
            ->orderBy(['elements_sites.id' => SORT_ASC]);

        if (!$scope->includeDrafts) {
            $query->andWhere(['elements.draftId' => null]);
        }

        if (!$scope->includeRevisions) {
            $query->andWhere(['elements.revisionId' => null]);
        }

        if (!$scope->includeTrashed) {
            $query->andWhere(['elements.dateDeleted' => null]);
        }

        if (!empty($scope->elementIds)) {
            $query->andWhere(['elements.id' => array_map('intval', $scope->elementIds)]);
        }

        $this->applyPrefilter($query, $rule);

        // Batched by hand rather than with `each()`: loading an element inside the loop can open a
        // second cursor on the same connection, and an unbuffered one dies when it does.
        $offset = 0;

        while (true) {
            $batch = $query->offset($offset)->limit(static::BATCH)->all();

            if ($batch === []) {
                return;
            }

            foreach ($batch as $row) {
                yield $row;
            }

            $offset += static::BATCH;
        }
    }

    /**
     * The literal string a rule requires, or null if there isn't one that can be relied on.
     *
     * A regular expression usually still contains a run of ordinary characters that any match must
     * contain — see {@see Literal}. When it doesn't, this returns null and the caller adds no
     * prefilter at all: slower, never wrong.
     */
    protected function needle(Rule $rule): ?string
    {
        $needle = $rule->isRegex() ? Literal::requiredSubstring($rule->find) : $rule->find;

        return $needle !== null && $needle !== '' ? $needle : null;
    }

    /**
     * A case-insensitive `LIKE` over one column, whichever database this is.
     */
    protected function like(string|Expression $column, string $needle): array
    {
        return [Craft::$app->getDb()->getIsPgsql() ? 'ilike' : 'like', $column, $needle];
    }

    /**
     * How the needle looks once it is inside a JSON string: `"` becomes `\"`, `/` becomes `\/`, a
     * newline becomes `\n`. Craft encodes content with `JSON_UNESCAPED_UNICODE`, so accented
     * characters are stored as themselves and need no second form.
     *
     * @return string[] The distinct forms worth searching for.
     */
    protected function jsonForms(string $needle): array
    {
        return array_values(array_unique([
            $needle,
            substr(json_encode($needle, JSON_UNESCAPED_UNICODE) ?: '""', 1, -1),
        ]));
    }
}
