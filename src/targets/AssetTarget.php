<?php

namespace justinholtweb\scrub\targets;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\Assets;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\models\Scope;
use justinholtweb\scrub\Plugin;

/**
 * Asset filenames and alt text.
 *
 * Filenames are the one slot in the plugin where the replacement touches something outside the
 * database: renaming an asset moves the file on disk and invalidates every transform of it. Craft
 * handles all of that through `newFilename`, which is why it's done that way here rather than by
 * writing the `filename` column — a renamed row whose file didn't move is a broken image on every
 * page it appears on.
 *
 * The new name is put through Craft's own filename sanitiser first. A replacement that would produce
 * `my report (final).pdf` becomes `my-report-final.pdf`, because a filename is not prose and the
 * alternative is a URL nobody can link to.
 */
class AssetTarget extends ElementTarget
{
    public const SLOT_FILENAME = 'filename';
    public const SLOT_ALT = 'alt';

    public static function handle(): string
    {
        return 'assets';
    }

    public function label(): string
    {
        return Craft::t('scrub', 'Asset filenames and alt text');
    }

    public function description(): string
    {
        return Craft::t('scrub', 'Renames the file on disk and clears its transforms. Alt text is changed in place.');
    }

    public function edition(): string
    {
        return Plugin::EDITION_PRO;
    }

    /**
     * Asset IDs whose filename slot has already been offered in this scan.
     *
     * A filename is one thing; an asset in a four-site install has four `elements_sites` rows. Alt
     * text really is per-site and is offered on each of them, but offering the filename four times
     * would triple-count the preview and try to rename a file that had already been renamed.
     *
     * @var array<int, true>
     */
    private array $filenamesOffered = [];

    protected function elementTypes(): array
    {
        return [Asset::class];
    }

    public function units(Rule $rule): \Generator
    {
        $this->filenamesOffered = [];

        yield from parent::units($rule);
    }

    protected function slotsFor(ElementInterface $element, Scope $scope): array
    {
        if (!$element instanceof Asset) {
            return [[], []];
        }

        $values = [];
        $labels = [];

        if (
            $scope->wantsSlot(self::SLOT_FILENAME) &&
            $element->filename !== '' &&
            !isset($this->filenamesOffered[$element->id])
        ) {
            $this->filenamesOffered[$element->id] = true;
            $values[self::SLOT_FILENAME] = $element->filename;
            $labels[self::SLOT_FILENAME] = Craft::t('app', 'Filename');
        }

        if ($scope->wantsSlot(self::SLOT_ALT) && ($element->alt ?? '') !== '') {
            $values[self::SLOT_ALT] = (string)$element->alt;
            $labels[self::SLOT_ALT] = Craft::t('app', 'Alternative text');
        }

        return [$values, $labels];
    }

    protected function applyValues(ElementInterface $element, array $values): void
    {
        if (!$element instanceof Asset) {
            return;
        }

        if (isset($values[self::SLOT_ALT])) {
            $element->alt = (string)$values[self::SLOT_ALT];
        }

        if (!isset($values[self::SLOT_FILENAME])) {
            return;
        }

        $filename = Assets::prepareAssetName((string)$values[self::SLOT_FILENAME]);

        if ($filename === '' || $filename === $element->filename) {
            return;
        }

        // `newFilename` plus the file-operations scenario is how Craft itself renames an asset: the
        // file is moved, the transforms are deleted and the record follows. Setting `filename`
        // directly changes the row and nothing else.
        $element->newFilename = $filename;
        $element->setScenario(Asset::SCENARIO_FILEOPS);
    }

    protected function applyPrefilter(Query $query, Rule $rule): void
    {
        $needle = $this->needle($rule);

        // The columns live on the assets table, not on `elements_sites`, so the join goes in
        // whether or not there's a needle to filter on.
        $query->innerJoin(['assets' => Table::ASSETS], '[[assets.id]] = [[elements_sites.elementId]]');

        if ($needle === null) {
            return;
        }

        // Alt text lives in two places: `assets.alt` is the default, `assets_sites.alt` the
        // per-site override. A site that translates its alt text stores it only in the second, so
        // searching only the first quietly misses every translated image.
        $query->leftJoin(
            ['assets_sites' => Table::ASSETS_SITES],
            '[[assets_sites.assetId]] = [[elements_sites.elementId]] AND [[assets_sites.siteId]] = [[elements_sites.siteId]]',
        );

        $query->andWhere([
            'or',
            $this->like('assets.filename', $needle),
            $this->like('assets.alt', $needle),
            $this->like('assets_sites.alt', $needle),
        ]);
    }
}
