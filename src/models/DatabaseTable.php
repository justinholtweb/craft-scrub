<?php

namespace justinholtweb\scrub\models;

use Craft;
use craft\base\Model;

/**
 * A table Scrub has been told it may rewrite.
 *
 * Content doesn't only live in elements. A form's success message, a redirect's destination, an
 * SEO description template — all of it is prose, all of it contains the old company name, and none
 * of it is reachable through the element system. This is how that gets covered without Scrub taking
 * a dependency on every plugin that might be installed.
 *
 * Nothing is enabled by default. Rewriting a table you didn't understand is how a site breaks in a
 * way no undo helps with, so each one is added deliberately, and a preview shows every row before
 * anything happens.
 */
class DatabaseTable extends Model
{
    /**
     * Tables that may never be added, whatever the settings say.
     *
     * Not a matter of taste. `elements_sites` is what the content target writes properly, through
     * element saves; a blind string replacement across it is exactly the behaviour Scrub exists to
     * improve on. The rest hold structure rather than prose, and a replacement in one of them
     * breaks the site rather than editing it.
     */
    public const FORBIDDEN = [
        'content',
        'elements',
        'elements_sites',
        'elements_owners',
        'fieldlayoutfields',
        'fieldlayouts',
        'info',
        'migrations',
        'projectconfig',
        'queue',
        'searchindex',
        'sessions',
        'shunnedmessages',
        'usergroups_users',
        'userpermissions',
        'userpermissions_usergroups',
        'userpermissions_users',
        'users',
    ];

    /** @var string Bare table name, no `{{%…}}` and no prefix. */
    public string $table = '';

    /** @var string The primary key column, used to address a row. */
    public string $primaryKey = 'id';

    /** @var string|null Column to name a row by in the preview. */
    public ?string $labelColumn = null;

    /** @var string[] Plain text columns to search. */
    public array $columns = [];

    /**
     * @var string[] Columns holding JSON. Decoded before searching and re-encoded after, so a
     *               replacement inside a serialized settings blob stays valid JSON.
     */
    public array $jsonColumns = [];

    /** @var string|null What to call this in the interface. */
    public ?string $label = null;

    public function label(): string
    {
        return $this->label ?? $this->table;
    }

    /**
     * Every column this definition touches.
     *
     * @return string[]
     */
    public function allColumns(): array
    {
        return array_values(array_unique(array_merge($this->columns, $this->jsonColumns)));
    }

    public function isJson(string $column): bool
    {
        return in_array($column, $this->jsonColumns, true);
    }

    /**
     * Whether the table is present and allowed. Checked before every scan, not just when saving
     * settings: a plugin can be uninstalled between one and the other.
     */
    public function isUsable(): bool
    {
        if ($this->table === '' || in_array($this->table, self::FORBIDDEN, true)) {
            return false;
        }

        if ($this->allColumns() === []) {
            return false;
        }

        $schema = Craft::$app->getDb()->getTableSchema('{{%' . $this->table . '}}');

        if ($schema === null) {
            return false;
        }

        // A column named in settings but missing from the table would otherwise become a SQL error
        // in the middle of a run.
        foreach (array_merge($this->allColumns(), [$this->primaryKey]) as $column) {
            if (!isset($schema->columns[$column])) {
                return false;
            }
        }

        return true;
    }

    protected function defineRules(): array
    {
        return [
            [['table', 'primaryKey'], 'required'],
            [['table', 'primaryKey', 'labelColumn', 'label'], 'string'],
            [['columns', 'jsonColumns'], 'safe'],
            [['table'], 'validateNotForbidden'],
        ];
    }

    public function validateNotForbidden(): void
    {
        if (in_array($this->table, self::FORBIDDEN, true)) {
            $this->addError('table', Craft::t('scrub', '{table} is managed by Craft and can’t be rewritten directly.', [
                'table' => $this->table,
            ]));
        }
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'table' => $this->table,
            'primaryKey' => $this->primaryKey,
            'labelColumn' => $this->labelColumn,
            'columns' => array_values($this->columns),
            'jsonColumns' => array_values($this->jsonColumns),
            'label' => $this->label,
        ];
    }

    public static function fromArray(array $array): self
    {
        $table = new self();

        foreach ($array as $key => $value) {
            if ($table->canSetProperty($key)) {
                $table->$key = $value;
            }
        }

        foreach (['columns', 'jsonColumns'] as $list) {
            $table->$list = array_values(array_filter(
                is_array($table->$list) ? $table->$list : (preg_split('~[\s,]+~', (string)$table->$list) ?: []),
                static fn($column) => trim((string)$column) !== '',
            ));
        }

        $table->table = trim(str_replace(['{{%', '}}'], '', $table->table));

        return $table;
    }

    /**
     * Definitions for plugins people actually have installed, offered as one-click additions on the
     * settings screen. Suggestions only — nothing here is enabled without being asked for.
     *
     * @return self[]
     */
    public static function suggestions(): array
    {
        $candidates = [
            ['table' => 'formie_forms', 'columns' => ['handle'], 'jsonColumns' => ['settings'], 'labelColumn' => 'handle', 'label' => 'Formie forms'],
            ['table' => 'formie_notifications', 'columns' => ['name', 'subject', 'content'], 'labelColumn' => 'name', 'label' => 'Formie notifications'],
            ['table' => 'freeform_forms', 'columns' => ['name', 'description'], 'jsonColumns' => ['metadata'], 'labelColumn' => 'name', 'label' => 'Freeform forms'],
            ['table' => 'seomatic_metabundles', 'jsonColumns' => ['metaGlobalVars', 'metaSiteVars'], 'labelColumn' => 'sourceHandle', 'label' => 'SEOmatic metadata'],
            ['table' => 'retour_static_redirects', 'columns' => ['redirectSrcUrl', 'redirectDestUrl'], 'labelColumn' => 'redirectSrcUrl', 'label' => 'Retour redirects'],
            ['table' => 'navigation_nodes', 'columns' => ['url'], 'labelColumn' => 'url', 'label' => 'Navigation node URLs'],
            ['table' => 'commerce_products', 'columns' => [], 'labelColumn' => 'id', 'label' => 'Commerce products'],
            ['table' => 'systemmessages', 'columns' => ['subject', 'body'], 'labelColumn' => 'key', 'label' => 'System email messages'],
        ];

        $suggestions = [];

        foreach ($candidates as $candidate) {
            $table = self::fromArray($candidate);

            if ($table->isUsable()) {
                $suggestions[] = $table;
            }
        }

        return $suggestions;
    }
}
