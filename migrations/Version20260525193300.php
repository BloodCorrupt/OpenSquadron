<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525193300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_session table for tracking active logins';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `user_session` (id INT AUTO_INCREMENT NOT NULL, admin_id INT NOT NULL, session_id VARCHAR(255) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_activity_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_8849CBDEAADADF46 (session_id), INDEX IDX_8849CBDE642B8210 (admin_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE `user_session` ADD CONSTRAINT FK_8849CBDE642B8210 FOREIGN KEY (admin_id) REFERENCES `admin` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user_session` DROP FOREIGN KEY FK_8849CBDE642B8210');
        $this->addSql('DROP TABLE `user_session`');
    }
}
