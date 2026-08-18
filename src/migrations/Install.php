<?php

namespace justinholtweb\scrub\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Three tables: the rules, the runs, and — the one that matters — every individual change a run made.
 *
 * `scrub_changes` is what makes undo possible at all. A database backup is not an undo: restoring
 * one throws away every edit anybody made in between. A row per changed value, holding what it was
 * and what it became, is reversible on its own terms and can refuse to reverse anything that has
 * been edited since.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%scrub_rules}}', [
            'id' => $this->primaryKey(),

            'name' => $this->string()->notNull(),
            'handle' => $this->string(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),

            'find' => $this->text()->notNull(),
            'replace' => $this->text(),

            'mode' => $this->string(16)->notNull()->defaultValue('plain'),
            'caseSensitive' => $this->boolean()->notNull()->defaultValue(true),
            'preserveCase' => $this->boolean()->notNull()->defaultValue(false),
            'multiline' => $this->boolean()->notNull()->defaultValue(false),
            'dotAll' => $this->boolean()->notNull()->defaultValue(false),

            'targets' => $this->json(),
            'scope' => $this->json(),

            // Real-time rules never touch the database, so they have no runs and no undo. Turning
            // the rule off is the undo.
            'realtime' => $this->boolean()->notNull()->defaultValue(false),
            'realtimeUris' => $this->json(),
            'realtimeHtmlAware' => $this->boolean()->notNull()->defaultValue(true),

            'onSave' => $this->boolean()->notNull()->defaultValue(false),

            'cadence' => $this->string(16),
            'dateLastRun' => $this->dateTime(),

            'sortOrder' => $this->integer()->notNull()->defaultValue(0),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%scrub_rules}}', ['handle'], true);
        $this->createIndex(null, '{{%scrub_rules}}', ['enabled', 'realtime'], false);
        $this->createIndex(null, '{{%scrub_rules}}', ['enabled', 'onSave'], false);

        $this->createTable('{{%scrub_runs}}', [
            'id' => $this->primaryKey(),

            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'dryRun' => $this->boolean()->notNull()->defaultValue(false),

            // Where it came from: `cp`, `console`, `queue`, `save`, `schedule`.
            'source' => $this->string(16)->notNull()->defaultValue('cp'),

            'ruleId' => $this->integer(),

            // A sentence, so the history list needs no joins and stays readable after the rule it
            // names has been edited or deleted.
            'summary' => $this->text(),
            'note' => $this->text(),

            // The rule exactly as it ran, and a bounded sample of what it found. An audit record
            // that changes shape when the schema does is not one.
            'rule' => $this->json(),
            'report' => $this->json(),

            'unitCount' => $this->integer()->notNull()->defaultValue(0),
            'hitCount' => $this->integer()->notNull()->defaultValue(0),
            'changed' => $this->integer()->notNull()->defaultValue(0),
            'failed' => $this->integer()->notNull()->defaultValue(0),
            'scanned' => $this->integer()->notNull()->defaultValue(0),
            'duration' => $this->decimal(10, 3)->notNull()->defaultValue(0),

            // Set on the run that was undone, pointing at the run that undid it.
            'revertedByRunId' => $this->integer(),

            'userId' => $this->integer(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%scrub_runs}}', ['dateCreated'], false);
        $this->createIndex(null, '{{%scrub_runs}}', ['status'], false);
        $this->createIndex(null, '{{%scrub_runs}}', ['ruleId'], false);

        $this->createTable('{{%scrub_changes}}', [
            'id' => $this->primaryKey(),

            'runId' => $this->integer()->notNull(),

            'target' => $this->string(64)->notNull(),
            'ref' => $this->string(255)->notNull(),
            'slot' => $this->string(255)->notNull(),

            // Values are stored as JSON because a slot is not always a string — a table field's
            // value is a list of rows. `mediumText` because a CKEditor field can be enormous and a
            // `text` column silently truncates one at 64 KB, which would make the undo *wrong*
            // rather than absent.
            'oldValue' => $this->mediumText(),
            'newValue' => $this->mediumText(),

            'hits' => $this->integer()->notNull()->defaultValue(0),
            'reverted' => $this->boolean()->notNull()->defaultValue(false),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%scrub_changes}}', ['runId'], false);
        $this->createIndex(null, '{{%scrub_changes}}', ['target', 'ref'], false);

        // Deleting a run takes its changes with it — an undo ledger for a run nobody can see is
        // just weight. Deleting the *rule* must not: the history of what it did outlives it.
        $this->addForeignKey(null, '{{%scrub_changes}}', ['runId'], '{{%scrub_runs}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%scrub_runs}}', ['ruleId'], '{{%scrub_rules}}', ['id'], 'SET NULL', null);

        // And deleting the person who ran it must not delete the record that it happened.
        $this->addForeignKey(null, '{{%scrub_runs}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%scrub_changes}}');
        $this->dropTableIfExists('{{%scrub_runs}}');
        $this->dropTableIfExists('{{%scrub_rules}}');

        return true;
    }
}
