<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260524093006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin ADD subscription_expires_at DATETIME DEFAULT NULL, ADD subscription_package_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE admin ADD CONSTRAINT FK_880E0D7636A9EB9A FOREIGN KEY (subscription_package_id) REFERENCES subscription_package (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_880E0D7636A9EB9A ON admin (subscription_package_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `admin` DROP FOREIGN KEY FK_880E0D7636A9EB9A');
        $this->addSql('DROP INDEX IDX_880E0D7636A9EB9A ON `admin`');
        $this->addSql('ALTER TABLE `admin` DROP subscription_expires_at, DROP subscription_package_id');
    }
}
