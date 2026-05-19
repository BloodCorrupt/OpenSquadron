<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519034232 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE message (id INT AUTO_INCREMENT NOT NULL, direction VARCHAR(20) NOT NULL, content LONGTEXT DEFAULT NULL, meta_message_id VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL, timestamp DATETIME NOT NULL, subscriber_id INT NOT NULL, INDEX IDX_B6BD307F7808B1AD (subscriber_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subscriber (id INT AUTO_INCREMENT NOT NULL, phone_number VARCHAR(50) NOT NULL, name VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_AD005B696B01BC5B (phone_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F7808B1AD FOREIGN KEY (subscriber_id) REFERENCES subscriber (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F7808B1AD');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE subscriber');
    }
}
