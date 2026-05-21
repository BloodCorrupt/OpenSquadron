-- ============================================================
-- OpenSquadron - Skeleton Database Schema (Single-Database Multi-Tenant)
-- Generated: 2026-05-21
-- Import: mysql -u root -p opensquadron < skeleton.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ───── Users ─────
CREATE TABLE IF NOT EXISTS `admin` (
    id INT AUTO_INCREMENT NOT NULL,
    parent_id INT DEFAULT NULL,
    email VARCHAR(180) NOT NULL,
    roles JSON NOT NULL,
    password VARCHAR(255) NOT NULL,
    account_type VARCHAR(20) NOT NULL DEFAULT 'admin',
    team_enabled TINYINT(1) NOT NULL DEFAULT 0,
    name VARCHAR(255) DEFAULT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    UNIQUE INDEX UNIQ_880E0D76E7927C74 (email),
    INDEX IDX_ADMIN_PARENT (parent_id),
    CONSTRAINT FK_ADMIN_PARENT FOREIGN KEY (parent_id) REFERENCES `admin` (id) ON DELETE SET NULL,
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;


-- ───── AI Contexts ─────
CREATE TABLE IF NOT EXISTS `ai_context` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    agent_role VARCHAR(255) DEFAULT NULL,
    system_instruction LONGTEXT DEFAULT NULL,
    context_data LONGTEXT DEFAULT NULL,
    is_active TINYINT NOT NULL DEFAULT 0,
    INDEX IDX_AI_CONTEXT_OWNER (owner_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── AI Settings ─────
CREATE TABLE IF NOT EXISTS `ai_setting` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    provider VARCHAR(100) NOT NULL,
    api_key LONGTEXT DEFAULT NULL,
    api_endpoint LONGTEXT DEFAULT NULL,
    model VARCHAR(255) DEFAULT NULL,
    system_instruction LONGTEXT DEFAULT NULL,
    is_active TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    INDEX IDX_AI_SETTING_OWNER (owner_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Bot Flows ─────
CREATE TABLE IF NOT EXISTS `bot_flow` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    whatsapp_connection_id INT DEFAULT NULL,
    name VARCHAR(120) DEFAULT NULL,
    trigger_keyword VARCHAR(500) DEFAULT NULL,
    match_mode VARCHAR(20) DEFAULT 'exact' NOT NULL,
    flow_data JSON NOT NULL,
    is_active TINYINT NOT NULL DEFAULT 1,
    INDEX IDX_BOT_FLOW_OWNER (owner_id),
    INDEX IDX_D3665A02C664C80F (whatsapp_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Subscribers ─────
CREATE TABLE IF NOT EXISTS `subscriber` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    whats_app_connection_id INT DEFAULT NULL,
    phone_number VARCHAR(50) NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX IDX_SUBSCRIBER_OWNER (owner_id),
    INDEX IDX_AD005B696381BF43 (whats_app_connection_id),
    UNIQUE INDEX uniq_subscriber_phone_connection (phone_number, whats_app_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Messages ─────
CREATE TABLE IF NOT EXISTS `message` (
    id INT AUTO_INCREMENT NOT NULL,
    subscriber_id INT NOT NULL,
    direction VARCHAR(20) NOT NULL,
    content LONGTEXT DEFAULT NULL,
    meta_message_id VARCHAR(255) DEFAULT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'text',
    media_url VARCHAR(255) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'received',
    timestamp DATETIME NOT NULL,
    INDEX IDX_B6BD307F7808B1AD (subscriber_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Message Templates ─────
CREATE TABLE IF NOT EXISTS `message_template` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    whatsapp_connection_id INT DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    language VARCHAR(10) NOT NULL,
    status VARCHAR(50) NOT NULL,
    category VARCHAR(50) NOT NULL,
    components JSON DEFAULT NULL,
    INDEX IDX_MESSAGE_TEMPLATE_OWNER (owner_id),
    INDEX IDX_9E46DB92C664C80F (whatsapp_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── WhatsApp Connections ─────
CREATE TABLE IF NOT EXISTS `whatsapp_connection` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    active_context_id INT DEFAULT NULL,
    business_account_id VARCHAR(255) NOT NULL,
    phone_number_id VARCHAR(255) DEFAULT NULL,
    label VARCHAR(255) DEFAULT NULL,
    phone_number VARCHAR(50) DEFAULT NULL,
    encrypted_access_token LONGTEXT NOT NULL,
    verify_token VARCHAR(64) NOT NULL,
    webhook_url VARCHAR(255) DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    ai_active TINYINT NOT NULL DEFAULT 0,
    agent_name VARCHAR(255) DEFAULT NULL,
    agent_role VARCHAR(255) DEFAULT NULL,
    context_data LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    INDEX IDX_WHATSAPP_CONNECTION_OWNER (owner_id),
    INDEX IDX_5E00F7954AA2A339 (active_context_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Doctrine Migrations Tracking ─────
CREATE TABLE IF NOT EXISTS `doctrine_migration_versions` (
    version VARCHAR(191) NOT NULL,
    executed_at DATETIME DEFAULT NULL,
    execution_time INT DEFAULT NULL,
    PRIMARY KEY (version)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Foreign Keys ─────
ALTER TABLE `message`
    ADD CONSTRAINT FK_B6BD307F7808B1AD FOREIGN KEY (subscriber_id) REFERENCES subscriber (id) ON DELETE CASCADE;

ALTER TABLE `whatsapp_connection`
    ADD CONSTRAINT FK_5E00F7954AA2A339 FOREIGN KEY (active_context_id) REFERENCES ai_context (id) ON DELETE SET NULL;

ALTER TABLE `bot_flow`
    ADD CONSTRAINT FK_D3665A02C664C80F FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE;

ALTER TABLE `message_template`
    ADD CONSTRAINT FK_9E46DB92C664C80F FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE;

ALTER TABLE `subscriber`
    ADD CONSTRAINT FK_AD005B696381BF43 FOREIGN KEY (whats_app_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE;

-- ───── Workspace Owner Constraints ─────
ALTER TABLE `ai_context`
    ADD CONSTRAINT FK_AI_CONTEXT_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `ai_setting`
    ADD CONSTRAINT FK_AI_SETTING_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `whatsapp_connection`
    ADD CONSTRAINT FK_WHATSAPP_CONN_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `bot_flow`
    ADD CONSTRAINT FK_BOT_FLOW_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `subscriber`
    ADD CONSTRAINT FK_SUBSCRIBER_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `message_template`
    ADD CONSTRAINT FK_MSG_TEMP_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

-- ───── Seed: Default Admin User ─────
-- Password: "admin123" (bcrypt hash — change immediately after first login)
INSERT INTO `admin` (email, roles, password) VALUES
    ('admin@opensquadron.local', '["ROLE_ADMIN"]', '$2y$13$dCqXfD9w9XB/Dxr2r4DD5u3ihrcsCgpBLq2LOnyfyHWM8pj2Hc4Ty');

-- ───── Seed: Default AI Setting Row ─────
INSERT INTO `ai_setting` (provider, is_active, created_at) VALUES
    ('openai', 0, NOW());

-- ───── Seed: Migration Versions ─────
INSERT INTO `doctrine_migration_versions` (version, executed_at, execution_time) VALUES
    ('DoctrineMigrations\\Version20260518173921', NOW(), 11),
    ('DoctrineMigrations\\Version20260518175227', NOW(), 14),
    ('DoctrineMigrations\\Version20260519034232', NOW(), 52),
    ('DoctrineMigrations\\Version20260520071518', NOW(), 153),
    ('DoctrineMigrations\\Version20260520090507', NOW(), 15),
    ('DoctrineMigrations\\Version20260520091945', NOW(), 97),
    ('DoctrineMigrations\\Version20260520093159', NOW(), 82);

SET FOREIGN_KEY_CHECKS = 1;
