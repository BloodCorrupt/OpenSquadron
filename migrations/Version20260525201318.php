<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260525201318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_session CHANGE created_at created_at DATETIME NOT NULL, CHANGE last_activity_at last_activity_at DATETIME NOT NULL');
        $this->addSql('DROP INDEX uniq_8849cbdeaadadf46 ON user_session');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8849CBDE613FECDF ON user_session (session_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user_session` CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE last_activity_at last_activity_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('DROP INDEX uniq_8849cbde613fecdf ON `user_session`');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8849CBDEAADADF46 ON `user_session` (session_id)');
    }
}
