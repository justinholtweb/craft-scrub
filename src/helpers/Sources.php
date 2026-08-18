<?php

namespace justinholtweb\scrub\helpers;

use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;

/**
 * Builds the source and field lists the scope form is made of.
 *
 * One flat list of `type:id` keys rather than a separate control per element type. The alternative —
 * a sections picker, a volumes picker, a groups picker, each appearing and disappearing as the
 * element type checkboxes change — is six widgets to express what is nearly always one sentence:
 * "the news section and the blog".
 */
class Sources
{
    /**
     * Every source on the site, grouped by element type for an optgroup-style list.
     *
     * @return array<string, array<int, array{label: string, value: string}>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $groups[Entry::pluralDisplayName()][] = [
                'label' => $section->name,
                'value' => 'section:' . $section->id,
            ];
        }

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $type) {
            $groups[Craft::t('scrub', 'Entry types')][] = [
                'label' => $type->name,
                'value' => 'entryType:' . $type->id,
            ];
        }

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $groups[Asset::pluralDisplayName()][] = [
                'label' => $volume->name,
                'value' => 'volume:' . $volume->id,
            ];
        }

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $groups[Category::pluralDisplayName()][] = [
                'label' => $group->name,
                'value' => 'categoryGroup:' . $group->id,
            ];
        }

        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $groups[Tag::pluralDisplayName()][] = [
                'label' => $group->name,
                'value' => 'tagGroup:' . $group->id,
            ];
        }

        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $groups[User::pluralDisplayName()][] = [
                'label' => $group->name,
                'value' => 'userGroup:' . $group->id,
            ];
        }

        foreach (Craft::$app->getGlobals()->getAllSets() as $set) {
            $groups[GlobalSet::pluralDisplayName()][] = [
                'label' => $set->name,
                'value' => 'globalSet:' . $set->id,
            ];
        }

        return $groups;
    }

    /**
     * Every custom field on the site that could hold text, plus the pseudo-fields for title and slug.
     *
     * Relation fields are left out: their value is a list of IDs, so a text replacement can never
     * match one, and listing them only makes the picker longer.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function fields(): array
    {
        $options = [
            ['label' => Craft::t('app', 'Title'), 'value' => 'title'],
            ['label' => Craft::t('app', 'Slug'), 'value' => 'slug'],
        ];

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($field instanceof \craft\base\ElementContainerFieldInterface || $field instanceof \craft\fields\BaseRelationField) {
                continue;
            }

            $options[] = [
                'label' => sprintf('%s (%s)', $field->name, $field->handle),
                'value' => $field->handle,
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public static function elementTypes(): array
    {
        $options = [];

        foreach (\justinholtweb\scrub\models\Scope::supportedElementTypes() as $type) {
            if (class_exists($type)) {
                $options[] = ['label' => $type::pluralDisplayName(), 'value' => $type];
            }
        }

        return $options;
    }

    /**
     * @return array<int, array{label: string, value: int}>
     */
    public static function sites(): array
    {
        return array_map(
            static fn($site) => ['label' => $site->name, 'value' => $site->id],
            Craft::$app->getSites()->getAllSites(),
        );
    }
}
