<?php

namespace justinholtweb\scrub\tests\unit;

use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\models\Scope;
use PHPUnit\Framework\TestCase;

/**
 * Building a scope out of posted form data.
 *
 * These exist because of a real failure: every list control on the Replace screen posts an empty
 * string when nothing is ticked, the list properties are typed `array`, and the assignment threw a
 * TypeError before anything could tidy it up. The screen's own instructions say "leave every box
 * unticked to search all of them", so the documented path was the broken one, and it broke on the
 * preview — the feature the whole plugin is sold on.
 *
 * The shape to remember: Craft's `checkboxGroup` and `multiselect` macros emit a hidden input under
 * the *bare* name so the key is always present, alongside the real controls under `name[]`. Tick
 * nothing and PHP hands you `''`; tick anything and it hands you an array.
 */
class ScopeFromArrayTest extends TestCase
{
    public function testEmptyCheckboxGroupBecomesAnEmptyArray(): void
    {
        $scope = Scope::fromArray([
            'elementTypes' => '',
            'sourceKeys' => '',
            'fieldHandles' => '',
            'siteIds' => '',
            'elementIds' => '',
        ]);

        self::assertSame([], $scope->elementTypes);
        self::assertSame([], $scope->sourceKeys);
        self::assertSame([], $scope->fieldHandles);
        self::assertSame([], $scope->siteIds);
        self::assertSame([], $scope->elementIds);
    }

    public function testTickedBoxesSurvive(): void
    {
        $scope = Scope::fromArray([
            'elementTypes' => ['craft\elements\Entry', 'craft\elements\Category'],
            'sourceKeys' => ['section:3'],
        ]);

        self::assertSame(['craft\elements\Entry', 'craft\elements\Category'], $scope->elementTypes);
        self::assertSame(['section:3'], $scope->sourceKeys);
    }

    /**
     * The hidden field leaves an empty slot in front of the real values when at least one box is
     * ticked. It has to go, or it becomes a source key that matches nothing and silently empties
     * the run — which is the failure the filtering was written for in the first place.
     */
    public function testTheHiddenFieldsEmptySlotIsDropped(): void
    {
        $scope = Scope::fromArray(['sourceKeys' => ['', 'section:3', '', 'volume:1']]);

        self::assertSame(['section:3', 'volume:1'], $scope->sourceKeys);
    }

    public function testNullsAreDroppedToo(): void
    {
        $scope = Scope::fromArray(['fieldHandles' => ['body', null, 'summary']]);

        self::assertSame(['body', 'summary'], $scope->fieldHandles);
    }

    public function testAScalarValueIsWrappedRatherThanThrowing(): void
    {
        // A single-value multiselect can arrive unwrapped.
        $scope = Scope::fromArray(['siteIds' => '2']);

        self::assertSame(['2'], $scope->siteIds);
    }

    public function testNoScopeAtAllIsAllowed(): void
    {
        $scope = Scope::fromArray(null);

        self::assertSame([], $scope->elementTypes);
        self::assertSame([], $scope->sourceKeys);
    }

    /**
     * `Rule::fromArray` has the same trap: `targets` and `realtimeUris` are typed `array` and the
     * form posts a bare empty string for an untouched checkbox group.
     */
    public function testRuleSurvivesAnEmptyTargetsGroup(): void
    {
        $rule = Rule::fromArray([
            'find' => 'Acme Ltd',
            'replace' => 'Acme Inc',
            'targets' => '',
            'realtimeUris' => '',
            'scope' => ['elementTypes' => ''],
        ]);

        self::assertSame('Acme Ltd', $rule->find);
        self::assertSame([], $rule->realtimeUris);
        self::assertSame([], $rule->scope->elementTypes);

        // Empty targets is not silently treated as "search everything" — that would look like a
        // clean run that found nothing. It stays empty here and `Rule::validateTargets()` is what
        // turns it into "Pick at least one thing to search." (not asserted here: these are plain
        // unit tests, and Yii's validator needs an application).
        self::assertSame([], $rule->targets);
    }

    public function testAbsentTargetsFallBackToContent(): void
    {
        // Not the same as an empty group: nothing posted at all is the console's shape, and there
        // the documented default is the content target.
        $rule = Rule::fromArray(['find' => 'x']);

        self::assertSame(['content'], $rule->targets);
    }

    public function testRuleKeepsTheTargetsThatWereTicked(): void
    {
        $rule = Rule::fromArray([
            'find' => 'x',
            'targets' => ['', 'content', 'slugs'],
        ]);

        self::assertSame(['content', 'slugs'], $rule->targets);
    }
}
