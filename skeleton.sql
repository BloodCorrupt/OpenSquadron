-- ============================================================
-- OpenSquadron - Skeleton Database Schema
-- Generated: 2026-05-20
-- Import: mysql -u root -p opensquadron < skeleton.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ───── Users ─────
CREATE TABLE IF NOT EXISTS `admin` (
    id INT AUTO_INCREMENT NOT NULL,
    email VARCHAR(180) NOT NULL,
    roles JSON NOT NULL,
    password VARCHAR(255) NOT NULL,
    UNIQUE INDEX UNIQ_880E0D76E7927C74 (email),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── AI Contexts ─────
CREATE TABLE IF NOT EXISTS `ai_context` (
    id INT AUTO_INCREMENT NOT NULL,
    name VARCHAR(255) NOT NULL,
    agent_role VARCHAR(255) DEFAULT NULL,
    system_instruction LONGTEXT DEFAULT NULL,
    context_data LONGTEXT DEFAULT NULL,
    is_active TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── AI Settings ─────
CREATE TABLE IF NOT EXISTS `ai_setting` (
    id INT AUTO_INCREMENT NOT NULL,
    provider VARCHAR(100) NOT NULL,
    api_key LONGTEXT DEFAULT NULL,
    api_endpoint LONGTEXT DEFAULT NULL,
    model VARCHAR(255) DEFAULT NULL,
    system_instruction LONGTEXT DEFAULT NULL,
    is_active TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Bot Flows ─────
CREATE TABLE IF NOT EXISTS `bot_flow` (
    id INT AUTO_INCREMENT NOT NULL,
    whatsapp_connection_id INT DEFAULT NULL,
    name VARCHAR(120) DEFAULT NULL,
    trigger_keyword VARCHAR(500) DEFAULT NULL,
    match_mode VARCHAR(20) DEFAULT 'exact' NOT NULL,
    flow_data JSON NOT NULL,
    is_active TINYINT NOT NULL DEFAULT 1,
    INDEX IDX_D3665A02C664C80F (whatsapp_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Subscribers ─────
CREATE TABLE IF NOT EXISTS `subscriber` (
    id INT AUTO_INCREMENT NOT NULL,
    phone_number VARCHAR(50) NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX UNIQ_AD005B696B01BC5B (phone_number),
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
    whatsapp_connection_id INT DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    language VARCHAR(10) NOT NULL,
    status VARCHAR(50) NOT NULL,
    category VARCHAR(50) NOT NULL,
    components JSON DEFAULT NULL,
    INDEX IDX_9E46DB92C664C80F (whatsapp_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── WhatsApp Connections ─────
CREATE TABLE IF NOT EXISTS `whatsapp_connection` (
    id INT AUTO_INCREMENT NOT NULL,
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
    ADD CONSTRAINT FK_B6BD307F7808B1AD FOREIGN KEY (subscriber_id) REFERENCES subscriber (id);

ALTER TABLE `whatsapp_connection`
    ADD CONSTRAINT FK_5E00F7954AA2A339 FOREIGN KEY (active_context_id) REFERENCES ai_context (id) ON DELETE SET NULL;

ALTER TABLE `bot_flow`
    ADD CONSTRAINT FK_D3665A02C664C80F FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE;

ALTER TABLE `message_template`
    ADD CONSTRAINT FK_9E46DB92C664C80F FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE;

-- ───── Seed: Default Admin User ─────
-- Password: "admin" (bcrypt hash — change immediately after first login)
INSERT INTO `admin` (email, roles, password) VALUES
    ('admin@opensquadron.local', '["ROLE_ADMIN"]', '$2y$13$dGKh0HQwLm8VxQnXpqFKjuahGkDfvLPGqGpX.qlzJZ0yL9snIYGd.');

-- ───── Seed: Default AI Setting Row ─────
INSERT INTO `ai_setting` (provider, is_active, created_at) VALUES
    ('openai', 0, NOW());

SET FOREIGN_KEY_CHECKS = 1;
