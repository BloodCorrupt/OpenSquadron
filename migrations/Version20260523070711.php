<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260523070711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE facebook_drip_sequence (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(200) NOT NULL, preferred_time VARCHAR(30) DEFAULT \'anytime\' NOT NULL, timezone VARCHAR(50) DEFAULT \'UTC\' NOT NULL, message_tag VARCHAR(60) DEFAULT \'NON_PROMOTIONAL_SUBSCRIPTION\' NOT NULL, allow_reentry TINYINT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, steps_count INT DEFAULT 0 NOT NULL, graph_data JSON DEFAULT NULL, owner_id INT DEFAULT NULL, facebook_connection_id INT DEFAULT NULL, INDEX IDX_FD73AE227E3C61F9 (owner_id), INDEX IDX_FD73AE22BE73D3C2 (facebook_connection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE whatsapp_drip_sequence (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(200) NOT NULL, `trigger` VARCHAR(60) DEFAULT \'NEW_SUBSCRIBER\' NOT NULL, preferred_time VARCHAR(30) DEFAULT \'anytime\' NOT NULL, timezone VARCHAR(50) DEFAULT \'UTC\' NOT NULL, message_tag VARCHAR(60) DEFAULT \'NON_PROMOTIONAL_SUBSCRIPTION\' NOT NULL, allow_reentry TINYINT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, steps_count INT DEFAULT 0 NOT NULL, graph_data JSON DEFAULT NULL, owner_id INT DEFAULT NULL, whatsapp_connection_id INT DEFAULT NULL, INDEX IDX_8564B5EF7E3C61F9 (owner_id), INDEX IDX_8564B5EFC664C80F (whatsapp_connection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE facebook_drip_sequence ADD CONSTRAINT FK_FD73AE227E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facebook_drip_sequence ADD CONSTRAINT FK_FD73AE22BE73D3C2 FOREIGN KEY (facebook_connection_id) REFERENCES facebook_connection (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE whatsapp_drip_sequence ADD CONSTRAINT FK_8564B5EF7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE whatsapp_drip_sequence ADD CONSTRAINT FK_8564B5EFC664C80F FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE facebook_drip_sequence DROP FOREIGN KEY FK_FD73AE227E3C61F9');
        $this->addSql('ALTER TABLE facebook_drip_sequence DROP FOREIGN KEY FK_FD73AE22BE73D3C2');
        $this->addSql('ALTER TABLE whatsapp_drip_sequence DROP FOREIGN KEY FK_8564B5EF7E3C61F9');
        $this->addSql('ALTER TABLE whatsapp_drip_sequence DROP FOREIGN KEY FK_8564B5EFC664C80F');
        $this->addSql('DROP TABLE facebook_drip_sequence');
        $this->addSql('DROP TABLE whatsapp_drip_sequence');
    }
}
