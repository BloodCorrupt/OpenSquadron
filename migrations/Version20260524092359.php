<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260524092359 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE subscription_package (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, price DOUBLE PRECISION NOT NULL, validity_days INT NOT NULL, is_reseller_package TINYINT DEFAULT 0 NOT NULL, features JSON DEFAULT NULL, owner_id INT NOT NULL, INDEX IDX_AD7D870E7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE subscription_package ADD CONSTRAINT FK_AD7D870E7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subscription_package DROP FOREIGN KEY FK_AD7D870E7E3C61F9');
        $this->addSql('DROP TABLE subscription_package');
    }
}
