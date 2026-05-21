<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260521094438 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE facebook_bot_flow (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) DEFAULT NULL, trigger_keyword VARCHAR(500) DEFAULT NULL, match_mode VARCHAR(20) DEFAULT \'exact\' NOT NULL, flow_data JSON NOT NULL, is_active TINYINT NOT NULL, owner_id INT DEFAULT NULL, facebook_connection_id INT DEFAULT NULL, INDEX IDX_14C7C9AA7E3C61F9 (owner_id), INDEX IDX_14C7C9AABE73D3C2 (facebook_connection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE whatsapp_bot_flow (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) DEFAULT NULL, trigger_keyword VARCHAR(500) DEFAULT NULL, match_mode VARCHAR(20) DEFAULT \'exact\' NOT NULL, flow_data JSON NOT NULL, is_active TINYINT NOT NULL, owner_id INT DEFAULT NULL, whatsapp_connection_id INT DEFAULT NULL, INDEX IDX_68901F927E3C61F9 (owner_id), INDEX IDX_68901F92C664C80F (whatsapp_connection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE facebook_bot_flow ADD CONSTRAINT FK_14C7C9AA7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facebook_bot_flow ADD CONSTRAINT FK_14C7C9AABE73D3C2 FOREIGN KEY (facebook_connection_id) REFERENCES facebook_connection (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE whatsapp_bot_flow ADD CONSTRAINT FK_68901F927E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE whatsapp_bot_flow ADD CONSTRAINT FK_68901F92C664C80F FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bot_flow DROP FOREIGN KEY `FK_BOT_FLOW_OWNER`');
        $this->addSql('ALTER TABLE bot_flow DROP FOREIGN KEY `FK_D3665A02C664C80F`');
        $this->addSql('DROP TABLE bot_flow');
        $this->addSql('ALTER TABLE admin DROP FOREIGN KEY `FK_ADMIN_PARENT`');
        $this->addSql('DROP INDEX idx_admin_parent ON admin');
        $this->addSql('CREATE INDEX IDX_880E0D76727ACA70 ON admin (parent_id)');
        $this->addSql('ALTER TABLE admin ADD CONSTRAINT `FK_ADMIN_PARENT` FOREIGN KEY (parent_id) REFERENCES admin (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ai_context DROP FOREIGN KEY `FK_AI_CONTEXT_OWNER`');
        $this->addSql('ALTER TABLE ai_context CHANGE is_active is_active TINYINT NOT NULL');
        $this->addSql('DROP INDEX idx_ai_context_owner ON ai_context');
        $this->addSql('CREATE INDEX IDX_DE588BAD7E3C61F9 ON ai_context (owner_id)');
        $this->addSql('ALTER TABLE ai_context ADD CONSTRAINT `FK_AI_CONTEXT_OWNER` FOREIGN KEY (owner_id) REFERENCES admin (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_setting DROP FOREIGN KEY `FK_AI_SETTING_OWNER`');
        $this->addSql('ALTER TABLE ai_setting CHANGE is_active is_active TINYINT NOT NULL');
        $this->addSql('DROP INDEX idx_ai_setting_owner ON ai_setting');
        $this->addSql('CREATE INDEX IDX_A371B64B7E3C61F9 ON ai_setting (owner_id)');
        $this->addSql('ALTER TABLE ai_setting ADD CONSTRAINT `FK_AI_SETTING_OWNER` FOREIGN KEY (owner_id) REFERENCES admin (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facebook_connection DROP FOREIGN KEY `FK_FACEBOOK_CONN_OWNER`');
        $this->addSql('ALTER TABLE facebook_connection CHANGE status status VARCHAR(50) NOT NULL, CHANGE ai_active ai_active TINYINT NOT NULL');
        $this->addSql('DROP INDEX idx_facebook_connection_owner ON facebook_connection');
        $this->addSql('CREATE INDEX IDX_262F40D87E3C61F9 ON facebook_connection (owner_id)');
        $this->addSql('ALTER TABLE facebook_connection ADD CONSTRAINT `FK_FACEBOOK_CONN_OWNER` FOREIGN KEY (owner_id) REFERENCES admin (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY `FK_B6BD307F7808B1AD`');
        $this->addSql('ALTER TABLE message CHANGE type type VARCHAR(20) NOT NULL, CHANGE status status VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F7808B1AD FOREIGN KEY (subscriber_id) REFERENCES subscriber (id)');
        $this->addSql('ALTER TABLE message_template DROP FOREIGN KEY `FK_MSG_TEMP_OWNER`');
        $this->addSql('DROP INDEX idx_message_template_owner ON message_template');
        $this->addSql('CREATE INDEX IDX_9E46DB927E3C61F9 ON message_template (owner_id)');
        $this->addSql('ALTER TABLE message_template ADD CONSTRAINT `FK_MSG_TEMP_OWNER` FOREIGN KEY (owner_id) REFERENCES admin (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscriber DROP FOREIGN KEY `FK_SUBSCRIBER_FB_CONN`');
        $this->addSql('ALTER TABLE subscriber DROP FOREIGN KEY `FK_SUBSCRIBER_OWNER`');
        $this->addSql('ALTER TABLE subscriber ADD tags JSON DEFAULT NULL, ADD custom_attributes JSON DEFAULT NULL, ADD notes JSON DEFAULT NULL, ADD assigned_operator_id INT DEFAULT NULL, ADD assigned_whatsapp_flow_id INT DEFAULT NULL, ADD assigned_facebook_flow_id INT DEFAULT NULL, CHANGE channel channel VARCHAR(20) NOT NULL, CHANGE status status VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE subscriber ADD CONSTRAINT FK_AD005B697F7F786A FOREIGN KEY (assigned_operator_id) REFERENCES `admin` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE subscriber ADD CONSTRAINT FK_AD005B6942F7871F FOREIGN KEY (assigned_whatsapp_flow_id) REFERENCES whatsapp_bot_flow (id)');
        $this->addSql('ALTER TABLE subscriber ADD CONSTRAINT FK_AD005B69C9CE08D1 FOREIGN KEY (assigned_facebook_flow_id) REFERENCES facebook_bot_flow (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_AD005B697F7F786A ON subscriber (assigned_operator_id)');
        $this->addSql('CREATE INDEX IDX_AD005B6942F7871F ON subscriber (assigned_whatsapp_flow_id)');
        $this->addSql('CREATE INDEX IDX_AD005B69C9CE08D1 ON subscriber (assigned_facebook_flow_id)');
        $this->addSql('DROP INDEX idx_subscriber_owner ON subscriber');
        $this->addSql('CREATE INDEX IDX_AD005B697E3C61F9 ON subscriber (owner_id)');
        $this->addSql('DROP INDEX idx_subscriber_fb_conn ON subscriber');
        $this->addSql('CREATE INDEX IDX_AD005B69BE73D3C2 ON subscriber (facebook_connection_id)');
        $this->addSql('ALTER TABLE subscriber ADD CONSTRAINT `FK_SUBSCRIBER_FB_CONN` FOREIGN KEY (facebook_connection_id) REFERENCES facebook_connection (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscriber ADD CONSTRAINT `FK_SUBSCRIBER_OWNER` FOREIGN KEY (owner_id) REFERENCES admin (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE whatsapp_connection DROP FOREIGN KEY `FK_WHATSAPP_CONN_OWNER`');
        $this->addSql('ALTER TABLE whatsapp_connection CHANGE status status VARCHAR(50) NOT NULL, CHANGE ai_active ai_active TINYINT NOT NULL');
        $this->addSql('DROP INDEX idx_whatsapp_connection_owner ON whatsapp_connection');
        $this->addSql('CREATE INDEX IDX_5E00F7957E3C61F9 ON whatsapp_connection (owner_id)');
        $this->addSql('ALTER TABLE whatsapp_connection ADD CONSTRAINT `FK_WHATSAPP_CONN_OWNER` FOREIGN KEY (owner_id) REFERENCES admin (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bot_flow (id INT AUTO_INCREMENT NOT NULL, owner_id INT DEFAULT NULL, whatsapp_connection_id INT DEFAULT NULL, name VARCHAR(120) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, trigger_keyword VARCHAR(500) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, match_mode VARCHAR(20) CHARACTER SET utf8mb4 DEFAULT \'exact\' NOT NULL COLLATE `utf8mb4_general_ci`, flow_data JSON NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, INDEX IDX_BOT_FLOW_OWNER (owner_id), INDEX IDX_D3665A02C664C80F (whatsapp_connection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE bot_flow ADD CONSTRAINT `FK_BOT_FLOW_OWNER` FOREIGN KEY (owner_id) REFERENCES admin (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bot_flow ADD CONSTRAINT `FK_D3665A02C664C80F` FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facebook_bot_flow DROP FOREIGN KEY FK_14C7C9AA7E3C61F9');
        $this->addSql('ALTER TABLE facebook_bot_flow DROP FOREIGN KEY FK_14C7C9AABE73D3C2');
        $this->addSql('ALTER TABLE whatsapp_bot_flow DROP FOREIGN KEY FK_68901F927E3C61F9');
        $this->addSql('ALTER TABLE whatsapp_bot_flow DROP FOREIGN KEY FK_68901F92C664C80F');
        $this->addSql('DROP TABLE facebook_bot_flow');
        $this->addSql('DROP TABLE whatsapp_bot_flow');
        $this->addSql('ALTER TABLE `admin` DROP FOREIGN KEY FK_880E0D76727ACA70');
        $this->addSql('DROP INDEX idx_880e0d76727aca70 ON `admin`');
        $this->addSql('CREATE INDEX IDX_ADMIN_PARENT ON `admin` (parent_id)');
        $this->addSql('ALTER TABLE `admin` ADD CONSTRAINT FK_880E0D76727ACA70 FOREIGN KEY (parent_id) REFERENCES `admin` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ai_context DROP FOREIGN KEY FK_DE588BAD7E3C61F9');
        $this->addSql('ALTER TABLE ai_context CHANGE is_active is_active TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('DROP INDEX idx_de588bad7e3c61f9 ON ai_context');
        $this->addSql('CREATE INDEX IDX_AI_CONTEXT_OWNER ON ai_context (owner_id)');
        $this->addSql('ALTER TABLE ai_context ADD CONSTRAINT FK_DE588BAD7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_setting DROP FOREIGN KEY FK_A371B64B7E3C61F9');
        $this->addSql('ALTER TABLE ai_setting CHANGE is_active is_active TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('DROP INDEX idx_a371b64b7e3c61f9 ON ai_setting');
        $this->addSql('CREATE INDEX IDX_AI_SETTING_OWNER ON ai_setting (owner_id)');
        $this->addSql('ALTER TABLE ai_setting ADD CONSTRAINT FK_A371B64B7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facebook_connection DROP FOREIGN KEY FK_262F40D87E3C61F9');
        $this->addSql('ALTER TABLE facebook_connection CHANGE status status VARCHAR(50) DEFAULT \'active\' NOT NULL, CHANGE ai_active ai_active TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('DROP INDEX idx_262f40d87e3c61f9 ON facebook_connection');
        $this->addSql('CREATE INDEX IDX_FACEBOOK_CONNECTION_OWNER ON facebook_connection (owner_id)');
        $this->addSql('ALTER TABLE facebook_connection ADD CONSTRAINT FK_262F40D87E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F7808B1AD');
        $this->addSql('ALTER TABLE message CHANGE type type VARCHAR(20) DEFAULT \'text\' NOT NULL, CHANGE status status VARCHAR(20) DEFAULT \'received\' NOT NULL');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT `FK_B6BD307F7808B1AD` FOREIGN KEY (subscriber_id) REFERENCES subscriber (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_template DROP FOREIGN KEY FK_9E46DB927E3C61F9');
        $this->addSql('DROP INDEX idx_9e46db927e3c61f9 ON message_template');
        $this->addSql('CREATE INDEX IDX_MESSAGE_TEMPLATE_OWNER ON message_template (owner_id)');
        $this->addSql('ALTER TABLE message_template ADD CONSTRAINT FK_9E46DB927E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscriber DROP FOREIGN KEY FK_AD005B697F7F786A');
        $this->addSql('ALTER TABLE subscriber DROP FOREIGN KEY FK_AD005B6942F7871F');
        $this->addSql('ALTER TABLE subscriber DROP FOREIGN KEY FK_AD005B69C9CE08D1');
        $this->addSql('DROP INDEX IDX_AD005B697F7F786A ON subscriber');
        $this->addSql('DROP INDEX IDX_AD005B6942F7871F ON subscriber');
        $this->addSql('DROP INDEX IDX_AD005B69C9CE08D1 ON subscriber');
        $this->addSql('ALTER TABLE subscriber DROP FOREIGN KEY FK_AD005B697E3C61F9');
        $this->addSql('ALTER TABLE subscriber DROP FOREIGN KEY FK_AD005B69BE73D3C2');
        $this->addSql('ALTER TABLE subscriber DROP tags, DROP custom_attributes, DROP notes, DROP assigned_operator_id, DROP assigned_whatsapp_flow_id, DROP assigned_facebook_flow_id, CHANGE channel channel VARCHAR(20) DEFAULT \'whatsapp\' NOT NULL, CHANGE status status VARCHAR(20) DEFAULT \'active\' NOT NULL');
        $this->addSql('DROP INDEX idx_ad005b697e3c61f9 ON subscriber');
        $this->addSql('CREATE INDEX IDX_SUBSCRIBER_OWNER ON subscriber (owner_id)');
        $this->addSql('DROP INDEX idx_ad005b69be73d3c2 ON subscriber');
        $this->addSql('CREATE INDEX IDX_SUBSCRIBER_FB_CONN ON subscriber (facebook_connection_id)');
        $this->addSql('ALTER TABLE subscriber ADD CONSTRAINT FK_AD005B697E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscriber ADD CONSTRAINT FK_AD005B69BE73D3C2 FOREIGN KEY (facebook_connection_id) REFERENCES facebook_connection (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE whatsapp_connection DROP FOREIGN KEY FK_5E00F7957E3C61F9');
        $this->addSql('ALTER TABLE whatsapp_connection CHANGE status status VARCHAR(50) DEFAULT \'active\' NOT NULL, CHANGE ai_active ai_active TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('DROP INDEX idx_5e00f7957e3c61f9 ON whatsapp_connection');
        $this->addSql('CREATE INDEX IDX_WHATSAPP_CONNECTION_OWNER ON whatsapp_connection (owner_id)');
        $this->addSql('ALTER TABLE whatsapp_connection ADD CONSTRAINT FK_5E00F7957E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE');
    }
}
