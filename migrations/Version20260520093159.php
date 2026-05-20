<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520093159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_AD005B696B01BC5B ON subscriber');
        $this->addSql('ALTER TABLE subscriber ADD whats_app_connection_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriber ADD CONSTRAINT FK_AD005B696381BF43 FOREIGN KEY (whats_app_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_AD005B696381BF43 ON subscriber (whats_app_connection_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_subscriber_phone_connection ON subscriber (phone_number, whats_app_connection_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subscriber DROP FOREIGN KEY FK_AD005B696381BF43');
        $this->addSql('DROP INDEX IDX_AD005B696381BF43 ON subscriber');
        $this->addSql('DROP INDEX uniq_subscriber_phone_connection ON subscriber');
        $this->addSql('ALTER TABLE subscriber DROP whats_app_connection_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AD005B696B01BC5B ON subscriber (phone_number)');
    }
}
