<?php

namespace justinholtweb\blaster\migrations;

use craft\db\Migration;
use craft\db\Table;
use justinholtweb\blaster\elements\Bar;
use justinholtweb\blaster\records\BarContentRecord;
use justinholtweb\blaster\records\BarRecord;
use justinholtweb\blaster\records\StatRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Elements first: the bars table hangs off `elements.id`, so dropping it while element
        // rows survive would leave records nothing can reach and garbage collection cannot see.
        $this->delete(Table::ELEMENTS, ['type' => Bar::class]);

        $this->dropTableIfExists(StatRecord::TABLE);
        $this->dropTableIfExists(BarContentRecord::TABLE);
        $this->dropTableIfExists(BarRecord::TABLE);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(BarRecord::TABLE, [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string()->notNull(),
            'position' => $this->string(16)->notNull()->defaultValue('top'),
            'priority' => $this->integer()->notNull()->defaultValue(0),

            // Bumped on every save. The runtime keys its dismissal record on it, so an edited bar
            // comes back for people who had dismissed the previous wording.
            'version' => $this->integer()->notNull()->defaultValue(1),

            'display' => $this->text(),
            'targeting' => $this->text(),
            'schedule' => $this->text(),
            'theme' => $this->text(),

            // Denormalised from the schedule model so that the element index can filter and sort
            // on status in SQL. A date buried in a JSON column cannot answer "which bars are live
            // right now" without reading every row.
            'startDate' => $this->dateTime(),
            'endDate' => $this->dateTime(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable(BarContentRecord::TABLE, [
            'id' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'message' => $this->text(),
            'buttonEnabled' => $this->boolean()->notNull()->defaultValue(false),
            'buttonLabel' => $this->string(),
            'buttonUrl' => $this->string(500),
            'buttonNewWindow' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]], [[siteId]])',
        ]);

        $this->createTable(StatRecord::TABLE, [
            'id' => $this->primaryKey(),
            'barId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'date' => $this->date()->notNull(),
            'views' => $this->integer()->notNull()->defaultValue(0),
            'clicks' => $this->integer()->notNull()->defaultValue(0),
            'dismissals' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // Not unique: a soft-deleted bar keeps its row, and holding a handle against a bar nobody
        // can see is worse than the duplicate it prevents. `Bars::handleIsTaken()` asks an
        // element query, which excludes trashed rows.
        $this->createIndex(null, BarRecord::TABLE, ['handle'], false);
        $this->createIndex(null, BarRecord::TABLE, ['position', 'priority'], false);
        $this->createIndex(null, BarRecord::TABLE, ['startDate'], false);
        $this->createIndex(null, BarRecord::TABLE, ['endDate'], false);
        $this->createIndex(null, BarContentRecord::TABLE, ['siteId'], false);
        $this->createIndex(null, StatRecord::TABLE, ['barId', 'siteId', 'date'], true);
        $this->createIndex(null, StatRecord::TABLE, ['date'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, BarRecord::TABLE, ['id'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, BarContentRecord::TABLE, ['id'], BarRecord::TABLE, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, BarContentRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, StatRecord::TABLE, ['barId'], BarRecord::TABLE, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, StatRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
    }
}
