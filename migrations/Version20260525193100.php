<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525193100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add 2FA properties to Admin entity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `admin` ADD totp_secret VARCHAR(255) DEFAULT NULL, ADD is_totp_authentication_enabled TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `admin` DROP totp_secret, DROP is_totp_authentication_enabled');
    }
}
