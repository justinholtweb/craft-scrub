<?php

namespace justinholtweb\scrub\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use justinholtweb\scrub\Plugin;

/**
 * Where a rule is allowed to look.
 *
 * The single biggest failing of Craft's own find and replace is that it has no scope at all: it
 * rewrites every row of `elements_sites`, in every site, including drafts and revisions, and there
 * is no way to say "only the news section" or "only the body field". Every property here exists
 * because somebody needed to say exactly that.
 *
 * The defaults are the *narrow* reading. Drafts, revisions and trashed elements are all excluded
 * until asked for — rewriting a revision quietly rewrites history, and rewriting a draft applies an
 * edit its author never made.
 */
class Scope extends Model
{
    /** Pseudo field handle standing for the element's title. */
    public const SLOT_TITLE = 'title';

    /**
     * @var int[] Site IDs to search. Empty means every site the user can edit.
     */
    public array $siteIds = [];

    /**
     * @var string[] Element classes to search. Empty means every class the target supports.
     */
    public array $elementTypes = [];

    /**
     * @var string[] Sources, as `type:id` — `section:3`, `volume:1`, `entryType:12`, `categoryGroup:2`,
     *               `tagGroup:1`, `userGroup:4`, `globalSet:2`. Empty means all of them.
     *
     * Flat rather than nested per element type, because the form is one list and the check is one
     * `in_array`. A key for a source that has since been deleted simply never matches.
     */
    public array $sourceKeys = [];

    /**
     * @var string[] Field handles to search, plus the pseudo-handle `title`. Empty means the title
     *               and every field on the element.
     */
    public array $fieldHandles = [];

    /**
     * @var int[] Explicit element IDs. Empty means no restriction; set, it wins over everything
     *            except the source and field filters.
     */
    public array $elementIds = [];

    /**
     * @var bool Whether to rewrite drafts.
     *
     * Off. A draft is somebody's unfinished work, and a replacement applied to it appears in the
     * "changed" diff as an edit they didn't make and can't explain.
     */
    public bool $includeDrafts = false;

    /**
     * @var bool Whether to rewrite revisions.
     *
     * Off, and it should stay off. A revision is a record of what the content *was*. Editing one
     * doesn't correct history, it falsifies it — and it multiplies the size of the run by however
     * many revisions the site keeps.
     */
    public bool $includeRevisions = false;

    /**
     * @var bool Whether to rewrite elements in the trash.
     */
    public bool $includeTrashed = false;

    /**
     * The element classes this scope covers, resolved against what's actually installed.
     *
     * @return string[]
     */
    public function elementTypes(): array
    {
        if (!empty($this->elementTypes)) {
            return array_values(array_filter($this->elementTypes, 'class_exists'));
        }

        return array_values(array_filter(self::supportedElementTypes(), 'class_exists'));
    }

    /**
     * @return string[]
     */
    public static function supportedElementTypes(): array
    {
        return [
            Entry::class,
            Category::class,
            Tag::class,
            Asset::class,
            User::class,
            GlobalSet::class,
        ];
    }

    public function siteIds(): array
    {
        if (!empty($this->siteIds)) {
            return array_map('intval', $this->siteIds);
        }

        return array_map(static fn($site) => $site->id, Craft::$app->getSites()->getAllSites());
    }

    /**
     * Whether a field (or the title, as `title`) is in scope.
     */
    public function wantsSlot(string $handle): bool
    {
        // Protected fields are checked here rather than on the form, so that the console command,
        // the queue job and a rule that runs on save are all bound by the same list.
        if (Plugin::getInstance()?->getSettings()->isProtectedField($handle)) {
            return false;
        }

        return empty($this->fieldHandles) || in_array($handle, $this->fieldHandles, true);
    }

    /**
     * Whether an element's *source* is in scope — its section, volume, group or global set.
     *
     * Done in PHP rather than SQL on purpose. Expressing it in the discovery query would mean a
     * different join per element type, and by the time this is asked the candidate rows have
     * already been narrowed to the handful that contain the needle at all.
     */
    public function wantsElement(ElementInterface $element): bool
    {
        if (!empty($this->elementIds) && !in_array($element->id, array_map('intval', $this->elementIds), true)) {
            return false;
        }

        $keys = $this->sourceKeysFor($element);
        $protected = Plugin::getInstance()?->getSettings()->protectedSources ?? [];

        // Protection wins over selection. A section on this list is never rewritten, whatever the
        // form posted and whoever is running it.
        if ($protected !== [] && array_intersect($keys, $protected) !== []) {
            return false;
        }

        if (empty($this->sourceKeys)) {
            return true;
        }

        return array_intersect($keys, $this->sourceKeys) !== [];
    }

    /**
     * Every source key an element could be matched by. More than one for entries, which belong to a
     * section *and* an entry type, and for users, who can be in several groups.
     *
     * @return string[]
     */
    public function sourceKeysFor(ElementInterface $element, int $depth = 0): array
    {
        $keys = [];

        if ($element instanceof Entry) {
            $section = $element->getSection();

            if ($section !== null) {
                $keys[] = 'section:' . $section->id;
            }

            $keys[] = 'entryType:' . $element->getType()->id;

            // A Matrix or content-block entry has no section of its own. Scoping a run to the News
            // section and then not touching the Matrix blocks inside News entries would be a
            // surprise of the worst kind — the preview looks right and half the content is missed —
            // so a nested entry answers with its owner's sources as well as its own.
            if ($section === null && $depth < 5) {
                $owner = $element->getPrimaryOwner();

                if ($owner !== null) {
                    $keys = array_merge($keys, $this->sourceKeysFor($owner, $depth + 1));
                }
            }
        } elseif ($element instanceof Asset) {
            $keys[] = 'volume:' . $element->getVolumeId();
        } elseif ($element instanceof Category) {
            $keys[] = 'categoryGroup:' . $element->getGroup()->id;
        } elseif ($element instanceof Tag) {
            $keys[] = 'tagGroup:' . $element->getGroup()->id;
        } elseif ($element instanceof User) {
            foreach ($element->getGroups() as $group) {
                $keys[] = 'userGroup:' . $group->id;
            }
        } elseif ($element instanceof GlobalSet) {
            $keys[] = 'globalSet:' . $element->id;
        }

        return $keys;
    }

    protected function defineRules(): array
    {
        return [
            [['siteIds', 'elementTypes', 'sourceKeys', 'fieldHandles', 'elementIds'], 'safe'],
            [['includeDrafts', 'includeRevisions', 'includeTrashed'], 'boolean'],
        ];
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'siteIds' => array_map('intval', $this->siteIds),
            'elementTypes' => $this->elementTypes,
            'sourceKeys' => $this->sourceKeys,
            'fieldHandles' => $this->fieldHandles,
            'elementIds' => array_map('intval', $this->elementIds),
            'includeDrafts' => $this->includeDrafts,
            'includeRevisions' => $this->includeRevisions,
            'includeTrashed' => $this->includeTrashed,
        ];
    }

    /** The properties a checkbox group or multiselect posts into. */
    private const LISTS = ['siteIds', 'elementTypes', 'sourceKeys', 'fieldHandles', 'elementIds'];

    public static function fromArray(?array $array): self
    {
        $scope = new self();

        foreach ($array ?? [] as $key => $value) {
            if (!$scope->canSetProperty($key)) {
                continue;
            }

            // Cast on the way in, not after. Craft's checkbox groups and multiselects emit a
            // hidden field under the bare name so the key is always posted, so a control with
            // nothing ticked arrives as '' rather than as []. These properties are typed `array`,
            // and assigning '' to one is a TypeError — which fires here, before any amount of
            // tidying further down could help. Leaving every box unticked is the documented way
            // to say "search all of them", so this is the ordinary path, not an edge case.
            $scope->$key = in_array($key, self::LISTS, true) ? (array)$value : $value;
        }

        // And then drop the empty slot that same hidden field leaves behind, which would
        // otherwise become a source key that matches nothing and silently empties the run.
        foreach (self::LISTS as $list) {
            $scope->$list = array_values(array_filter(
                (array)$scope->$list,
                static fn($value) => $value !== '' && $value !== null,
            ));
        }

        return $scope;
    }

    /**
     * A one-line description, for the run ledger — which has to stay readable after the section it
     * names has been renamed or deleted.
     */
    public function describe(): string
    {
        $parts = [];

        if (!empty($this->elementTypes)) {
            $parts[] = implode(', ', array_map(
                static fn(string $type) => class_exists($type) ? $type::pluralDisplayName() : $type,
                $this->elementTypes,
            ));
        }

        if (!empty($this->sourceKeys)) {
            $parts[] = Craft::t('scrub', '{n} sources', ['n' => count($this->sourceKeys)]);
        }

        if (!empty($this->fieldHandles)) {
            $parts[] = Craft::t('scrub', 'fields: {fields}', ['fields' => implode(', ', $this->fieldHandles)]);
        }

        if (!empty($this->elementIds)) {
            $parts[] = Craft::t('scrub', '{n} specific elements', ['n' => count($this->elementIds)]);
        }

        foreach (['includeDrafts' => 'drafts', 'includeRevisions' => 'revisions', 'includeTrashed' => 'trashed'] as $property => $label) {
            if ($this->$property) {
                $parts[] = Craft::t('scrub', 'including {what}', ['what' => $label]);
            }
        }

        return $parts === [] ? Craft::t('scrub', 'everything') : implode(' · ', $parts);
    }
}
