<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds visual flow builder columns to bot_flow:
 *   - name: human-readable flow name
 *   - match_mode: exact|contains|starts_with
 *   - widens trigger_keyword to 500 chars and allows NULL (drafts)
 */
final class Version20260520071518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bot flow builder: add name + match_mode columns, widen trigger_keyword.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('bot_flow')) {
            // First-time install: create the full table with the new schema.
            $this->addSql('CREATE TABLE bot_flow (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(120) DEFAULT NULL,
                trigger_keyword VARCHAR(500) DEFAULT NULL,
                match_mode VARCHAR(20) NOT NULL DEFAULT \'exact\',
                flow_data JSON NOT NULL,
                is_active TINYINT(1) NOT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4');
            return;
        }

        $table = $schema->getTable('bot_flow');

        // Drop legacy unique index on trigger_keyword if it exists so multiple
        // flows can share keywords across different match modes.
        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique() && in_array('trigger_keyword', $index->getUnquotedColumns(), true)) {
                $this->addSql('ALTER TABLE bot_flow DROP INDEX ' . $index->getName());
            }
        }

        if (!$table->hasColumn('name')) {
            $this->addSql('ALTER TABLE bot_flow ADD name VARCHAR(120) DEFAULT NULL');
        }
        if (!$table->hasColumn('match_mode')) {
            $this->addSql('ALTER TABLE bot_flow ADD match_mode VARCHAR(20) NOT NULL DEFAULT \'exact\'');
        }

        // Relax the keyword column: nullable + 500 chars to allow comma-separated lists.
        $this->addSql('ALTER TABLE bot_flow MODIFY trigger_keyword VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('bot_flow')) {
            return;
        }
        $table = $schema->getTable('bot_flow');

        if ($table->hasColumn('match_mode')) {
            $this->addSql('ALTER TABLE bot_flow DROP COLUMN match_mode');
        }
        if ($table->hasColumn('name')) {
            $this->addSql('ALTER TABLE bot_flow DROP COLUMN name');
        }
        $this->addSql('ALTER TABLE bot_flow MODIFY trigger_keyword VARCHAR(255) NOT NULL');
    }
}
