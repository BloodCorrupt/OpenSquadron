<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529052400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_name, system_user_access_token, whatsapp_app_id, and whatsapp_encrypted_app_secret columns to meta_setting table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meta_setting ADD app_name VARCHAR(255) DEFAULT NULL, ADD system_user_access_token LONGTEXT DEFAULT NULL, ADD whatsapp_app_id VARCHAR(255) DEFAULT NULL, ADD whatsapp_encrypted_app_secret LONGTEXT DEFAULT NULL, ADD whatsapp_verify_token VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meta_setting DROP COLUMN app_name, DROP COLUMN system_user_access_token, DROP COLUMN whatsapp_app_id, DROP COLUMN whatsapp_encrypted_app_secret, DROP COLUMN whatsapp_verify_token');
    }
}
