<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529051300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add whatsapp_config_id column to meta_setting table for WhatsApp Embedded Signup';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meta_setting ADD whatsapp_config_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meta_setting DROP COLUMN whatsapp_config_id');
    }
}
