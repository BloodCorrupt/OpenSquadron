<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520091945 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bot_flow ADD whatsapp_connection_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bot_flow ADD CONSTRAINT FK_D3665A02C664C80F FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_D3665A02C664C80F ON bot_flow (whatsapp_connection_id)');
        $this->addSql('ALTER TABLE message_template ADD whatsapp_connection_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message_template ADD CONSTRAINT FK_9E46DB92C664C80F FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_9E46DB92C664C80F ON message_template (whatsapp_connection_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bot_flow DROP FOREIGN KEY FK_D3665A02C664C80F');
        $this->addSql('DROP INDEX IDX_D3665A02C664C80F ON bot_flow');
        $this->addSql('ALTER TABLE bot_flow DROP whatsapp_connection_id');
        $this->addSql('ALTER TABLE message_template DROP FOREIGN KEY FK_9E46DB92C664C80F');
        $this->addSql('DROP INDEX IDX_9E46DB92C664C80F ON message_template');
        $this->addSql('ALTER TABLE message_template DROP whatsapp_connection_id');
    }
}
