<?php

namespace justinholtweb\scrub\targets;

use Craft;
use craft\db\Query;
use craft\helpers\Json;
use Generator;
use justinholtweb\scrub\matching\Literal;
use justinholtweb\scrub\models\DatabaseTable;
use justinholtweb\scrub\models\Rule;
use justinholtweb\scrub\Plugin;
use RuntimeException;
use Throwable;

/**
 * Rows in tables that aren't elements.
 *
 * The awkward truth about a site-wide rename is that content isn't only in content. The old company
 * name is in a form's success message, in a redirect's destination, in an SEO description template,
 * in the body of a system email — none of it reachable through the element system, all of it left
 * behind by every find-and-replace tool, and all of it found later by a customer.
 *
 * Rather than depend on Formie, Freeform, SEOmatic and Retour (and be broken by each of their next
 * major versions), Scrub takes a list of tables and columns. Suggestions are offered for plugins
 * that are actually installed; nothing is enabled without being asked for, every table is checked to
 * exist before each scan, and the tables Craft manages itself are refused outright.
 */
class DatabaseTarget extends BaseTarget
{
    private const BATCH = 250;

    public static function handle(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return Craft::t('scrub', 'Other database tables');
    }

    public function description(): string
    {
        return Craft::t('scrub', 'Plugin tables you’ve configured — form messages, redirects, SEO metadata, system emails.');
    }

    public function edition(): string
    {
        return Plugin::EDITION_PRO;
    }

    /**
     * Hidden entirely until at least one table has been configured, rather than shown as a checkbox
     * that does nothing.
     */
    public function isSupported(): bool
    {
        return $this->tables() !== [];
    }

    /**
     * @return Generator<Unit>
     */
    public function units(Rule $rule): Generator
    {
        $needle = $rule->isRegex() ? Literal::requiredSubstring($rule->find) : $rule->find;
        $db = Craft::$app->getDb();
        $like = $db->getIsPgsql() ? 'ilike' : 'like';

        foreach ($this->tables() as $table) {
            $columns = array_values(array_unique(array_merge(
                [$table->primaryKey],
                $table->labelColumn !== null ? [$table->labelColumn] : [],
                $table->allColumns(),
            )));

            $query = (new Query())
                ->select($columns)
                ->from('{{%' . $table->table . '}}')
                ->orderBy([$table->primaryKey => SORT_ASC]);

            if ($needle !== null && $needle !== '') {
                $where = ['or'];

                foreach ($table->allColumns() as $column) {
                    $where[] = [$like, $column, $needle];

                    // JSON columns hold the needle escaped — `"` as `\"`, `/` as `\/`. Searching
                    // only the raw form finds nothing in exactly the columns this target exists for.
                    if ($table->isJson($column)) {
                        $encoded = substr(json_encode($needle, JSON_UNESCAPED_UNICODE) ?: '""', 1, -1);

                        if ($encoded !== $needle) {
                            $where[] = [$like, $column, $encoded];
                        }
                    }
                }

                $query->andWhere($where);
            }

            $offset = 0;

            while (true) {
                $rows = $query->offset($offset)->limit(self::BATCH)->all();

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $unit = $this->unitFor($table, $row);

                    if ($unit !== null) {
                        yield $unit;
                    }
                }

                $offset += self::BATCH;
            }
        }
    }

    public function unit(string $ref): ?Unit
    {
        [$tableName, $id] = $this->parseRef($ref);
        $table = $this->tables()[$tableName] ?? null;

        if ($table === null) {
            return null;
        }

        $row = (new Query())
            ->from('{{%' . $table->table . '}}')
            ->where([$table->primaryKey => $id])
            ->one();

        return $row !== null ? $this->unitFor($table, $row) : null;
    }

    public function write(Unit $unit, array $values): void
    {
        [$tableName, $id] = $this->parseRef($unit->ref);
        $table = $this->tables()[$tableName] ?? null;

        if ($table === null) {
            throw new RuntimeException(sprintf('%s is no longer a table Scrub may write to.', $tableName));
        }

        $columns = [];

        foreach ($values as $column => $value) {
            if (!in_array($column, $table->allColumns(), true)) {
                continue;
            }

            $columns[$column] = $table->isJson($column) ? Json::encode($value) : (string)$value;
        }

        if ($columns === []) {
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->update('{{%' . $table->table . '}}', $columns, [$table->primaryKey => $id])
            ->execute();
    }

    // -----------------------------------------------------------------------------------------

    /**
     * The configured tables, checked and keyed by name.
     *
     * @return array<string, DatabaseTable>
     */
    private function tables(): array
    {
        $tables = [];

        foreach (Plugin::getInstance()->getSettings()->databaseTables() as $table) {
            if ($table->isUsable()) {
                $tables[$table->table] = $table;
            }
        }

        return $tables;
    }

    private function unitFor(DatabaseTable $table, array $row): ?Unit
    {
        $values = [];
        $labels = [];

        foreach ($table->allColumns() as $column) {
            $value = $row[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($table->isJson($column)) {
                try {
                    $decoded = Json::decode((string)$value);
                } catch (Throwable) {
                    // A column declared as JSON that isn't. Treated as text rather than skipped —
                    // the replacement is still meaningful, and refusing would hide a real match.
                    $values[$column] = (string)$value;
                    $labels[$column] = $column;
                    continue;
                }

                if (is_array($decoded)) {
                    $values[$column] = $decoded;
                    $labels[$column] = $column;
                }

                continue;
            }

            $values[$column] = (string)$value;
            $labels[$column] = $column;
        }

        if ($values === []) {
            return null;
        }

        $id = $row[$table->primaryKey] ?? null;

        if ($id === null) {
            return null;
        }

        $label = $table->labelColumn !== null ? (string)($row[$table->labelColumn] ?? '') : '';

        return new Unit(
            target: self::handle(),
            ref: $table->table . ':' . $id,
            kind: $table->label(),
            label: $label !== '' ? $label : sprintf('#%s', $id),
            values: $values,
            slotLabels: $labels,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseRef(string $ref): array
    {
        // Split on the last colon: a table name can't contain one, an ID can't either, but this is
        // the shape that stays right if either assumption ever stops holding.
        $position = strrpos($ref, ':');

        if ($position === false) {
            return [$ref, ''];
        }

        return [substr($ref, 0, $position), substr($ref, $position + 1)];
    }
}
