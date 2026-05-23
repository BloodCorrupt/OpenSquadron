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
    INDEX IDX_880E0D76727ACA70 (parent_id),
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
    is_active TINYINT NOT NULL,
    INDEX IDX_DE588BAD7E3C61F9 (owner_id),
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
    is_active TINYINT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    INDEX IDX_A371B64B7E3C61F9 (owner_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Facebook Settings ─────
CREATE TABLE IF NOT EXISTS `facebook_setting` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    app_id VARCHAR(255) NOT NULL,
    encrypted_app_secret LONGTEXT NOT NULL,
    verify_token VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    INDEX IDX_E94FA4E37E3C61F9 (owner_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;


-- ───── Facebook Bot Flows ─────
CREATE TABLE IF NOT EXISTS `facebook_bot_flow` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    facebook_connection_id INT DEFAULT NULL,
    name VARCHAR(120) DEFAULT NULL,
    trigger_keyword VARCHAR(500) DEFAULT NULL,
    match_mode VARCHAR(20) DEFAULT 'exact' NOT NULL,
    flow_data JSON NOT NULL,
    is_active TINYINT NOT NULL,
    INDEX IDX_14C7C9AA7E3C61F9 (owner_id),
    INDEX IDX_14C7C9AABE73D3C2 (facebook_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── WhatsApp Bot Flows ─────
CREATE TABLE IF NOT EXISTS `whatsapp_bot_flow` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    whatsapp_connection_id INT DEFAULT NULL,
    name VARCHAR(120) DEFAULT NULL,
    trigger_keyword VARCHAR(500) DEFAULT NULL,
    match_mode VARCHAR(20) DEFAULT 'exact' NOT NULL,
    flow_data JSON NOT NULL,
    is_active TINYINT NOT NULL,
    INDEX IDX_68901F927E3C61F9 (owner_id),
    INDEX IDX_68901F92C664C80F (whatsapp_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Facebook Drip Sequences ─────
CREATE TABLE IF NOT EXISTS `facebook_drip_sequence` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    facebook_connection_id INT DEFAULT NULL,
    name VARCHAR(200) NOT NULL,
    preferred_time VARCHAR(30) NOT NULL DEFAULT 'anytime',
    timezone VARCHAR(50) NOT NULL DEFAULT 'UTC',
    message_tag VARCHAR(60) NOT NULL DEFAULT 'NON_PROMOTIONAL_SUBSCRIPTION',
    allow_reentry TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    steps_count INT NOT NULL DEFAULT 0,
    graph_data JSON DEFAULT NULL,
    INDEX IDX_FD73AE227E3C61F9 (owner_id),
    INDEX IDX_FD73AE22BE73D3C2 (facebook_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── WhatsApp Drip Sequences ─────
CREATE TABLE IF NOT EXISTS `whatsapp_drip_sequence` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    whatsapp_connection_id INT DEFAULT NULL,
    name VARCHAR(200) NOT NULL,
    `trigger` VARCHAR(60) NOT NULL DEFAULT 'NEW_SUBSCRIBER',
    preferred_time VARCHAR(30) NOT NULL DEFAULT 'anytime',
    timezone VARCHAR(50) NOT NULL DEFAULT 'UTC',
    message_tag VARCHAR(60) NOT NULL DEFAULT 'NON_PROMOTIONAL_SUBSCRIPTION',
    allow_reentry TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    steps_count INT NOT NULL DEFAULT 0,
    graph_data JSON DEFAULT NULL,
    INDEX IDX_8564B5EF7E3C61F9 (owner_id),
    INDEX IDX_8564B5EFC664C80F (whatsapp_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Facebook Action Buttons ─────
CREATE TABLE IF NOT EXISTS `facebook_action_button` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    facebook_connection_id INT DEFAULT NULL,
    facebook_bot_flow_id INT DEFAULT NULL,
    button_key VARCHAR(50) NOT NULL,
    button_label VARCHAR(100) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    reply_type VARCHAR(20) NOT NULL DEFAULT 'none',
    reply_text LONGTEXT DEFAULT NULL,
    INDEX IDX_4B9571957E3C61F9 (owner_id),
    INDEX IDX_4B957195BE73D3C2 (facebook_connection_id),
    INDEX IDX_4B957195AD962686 (facebook_bot_flow_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── WhatsApp Action Buttons ─────
CREATE TABLE IF NOT EXISTS `whatsapp_action_button` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    whats_app_connection_id INT DEFAULT NULL,
    whats_app_bot_flow_id INT DEFAULT NULL,
    button_key VARCHAR(50) NOT NULL,
    button_label VARCHAR(100) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    reply_type VARCHAR(20) NOT NULL DEFAULT 'none',
    reply_text LONGTEXT DEFAULT NULL,
    INDEX IDX_33826A587E3C61F9 (owner_id),
    INDEX IDX_33826A586381BF43 (whats_app_connection_id),
    INDEX IDX_33826A58B2B4395F (whats_app_bot_flow_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;


-- ───── Subscribers ─────
CREATE TABLE IF NOT EXISTS `subscriber` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    whats_app_connection_id INT DEFAULT NULL,
    facebook_connection_id INT DEFAULT NULL,
    phone_number VARCHAR(50) DEFAULT NULL,
    channel VARCHAR(20) NOT NULL,
    psid VARCHAR(255) DEFAULT NULL,
    name VARCHAR(255) DEFAULT NULL,
    status VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    tags JSON DEFAULT NULL,
    custom_attributes JSON DEFAULT NULL,
    notes JSON DEFAULT NULL,
    assigned_operator_id INT DEFAULT NULL,
    assigned_whatsapp_flow_id INT DEFAULT NULL,
    assigned_facebook_flow_id INT DEFAULT NULL,
    INDEX IDX_AD005B697E3C61F9 (owner_id),
    INDEX IDX_AD005B696381BF43 (whats_app_connection_id),
    INDEX IDX_AD005B69BE73D3C2 (facebook_connection_id),
    INDEX IDX_AD005B697F7F786A (assigned_operator_id),
    INDEX IDX_AD005B6942F7871F (assigned_whatsapp_flow_id),
    INDEX IDX_AD005B69C9CE08D1 (assigned_facebook_flow_id),
    UNIQUE INDEX uniq_subscriber_phone_connection (phone_number, whats_app_connection_id),
    UNIQUE INDEX uniq_subscriber_facebook_connection (psid, facebook_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;


-- ───── Messages ─────
CREATE TABLE IF NOT EXISTS `message` (
    id INT AUTO_INCREMENT NOT NULL,
    subscriber_id INT NOT NULL,
    direction VARCHAR(20) NOT NULL,
    content LONGTEXT DEFAULT NULL,
    meta_message_id VARCHAR(255) DEFAULT NULL,
    type VARCHAR(20) NOT NULL,
    media_url VARCHAR(255) DEFAULT NULL,
    status VARCHAR(20) NOT NULL,
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
    INDEX IDX_9E46DB927E3C61F9 (owner_id),
    INDEX IDX_9E46DB92C664C80F (whatsapp_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── WhatsApp Connections ─────
CREATE TABLE IF NOT EXISTS `whatsapp_connection` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    active_context_id INT DEFAULT NULL,
    business_account_id VARCHAR(255) NOT NULL,
    phone_number_id VARCHAR(255) NOT NULL,
    label VARCHAR(255) DEFAULT NULL,
    phone_number VARCHAR(50) DEFAULT NULL,
    encrypted_access_token LONGTEXT NOT NULL,
    verify_token VARCHAR(64) NOT NULL,
    webhook_url VARCHAR(255) DEFAULT NULL,
    status VARCHAR(50) NOT NULL,
    ai_active TINYINT NOT NULL,
    agent_name VARCHAR(255) DEFAULT NULL,
    agent_role VARCHAR(255) DEFAULT NULL,
    context_data LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    INDEX IDX_5E00F7957E3C61F9 (owner_id),
    INDEX IDX_5E00F7954AA2A339 (active_context_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Facebook Connections ─────
CREATE TABLE IF NOT EXISTS `facebook_connection` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT DEFAULT NULL,
    active_context_id INT DEFAULT NULL,
    page_id VARCHAR(255) NOT NULL,
    page_name VARCHAR(255) DEFAULT NULL,
    encrypted_page_access_token LONGTEXT NOT NULL,
    app_id VARCHAR(255) NOT NULL,
    encrypted_app_secret LONGTEXT NOT NULL,
    verify_token VARCHAR(64) NOT NULL,
    webhook_url VARCHAR(255) DEFAULT NULL,
    label VARCHAR(255) DEFAULT NULL,
    status VARCHAR(50) NOT NULL,
    ai_active TINYINT NOT NULL,
    agent_name VARCHAR(255) DEFAULT NULL,
    agent_role VARCHAR(255) DEFAULT NULL,
    context_data LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    INDEX IDX_262F40D87E3C61F9 (owner_id),
    INDEX IDX_262F40D84AA2A339 (active_context_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

-- ───── Facebook Comment Automation ─────
CREATE TABLE IF NOT EXISTS `facebook_comment_automation` (
    id INT AUTO_INCREMENT NOT NULL,
    owner_id INT NOT NULL,
    facebook_connection_id INT NOT NULL,
    post_id VARCHAR(255) DEFAULT NULL,
    campaign_name VARCHAR(255) DEFAULT NULL,
    automation_mode VARCHAR(50) DEFAULT 'generic',
    enable_comment_reply TINYINT(1) DEFAULT 0,
    hide_or_delete VARCHAR(50) DEFAULT NULL,
    offensive_keywords LONGTEXT DEFAULT NULL,
    offensive_private_reply_flow VARCHAR(255) DEFAULT NULL,
    send_reply_multiple_times TINYINT(1) DEFAULT 0,
    hide_comment_after_reply TINYINT(1) DEFAULT 0,
    ai_context_id VARCHAR(255) DEFAULT NULL,
    generic_comment_reply LONGTEXT DEFAULT NULL,
    generic_private_reply VARCHAR(255) DEFAULT NULL,
    generic_image_url VARCHAR(255) DEFAULT NULL,
    generic_video_url VARCHAR(255) DEFAULT NULL,
    fallback_comment_reply LONGTEXT DEFAULT NULL,
    fallback_private_reply VARCHAR(255) DEFAULT NULL,
    fallback_image_url VARCHAR(255) DEFAULT NULL,
    fallback_video_url VARCHAR(255) DEFAULT NULL,
    INDEX IDX_FB_COMMENT_AUTO_OWNER (owner_id),
    INDEX IDX_FB_COMMENT_AUTO_CONN (facebook_connection_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `facebook_comment_automation_rule` (
    id INT AUTO_INCREMENT NOT NULL,
    automation_campaign_id INT NOT NULL,
    filter_words LONGTEXT DEFAULT NULL,
    filter_match_type VARCHAR(50) DEFAULT 'exact',
    comment_reply_text LONGTEXT DEFAULT NULL,
    private_reply_flow_id VARCHAR(255) DEFAULT NULL,
    image_reply_url VARCHAR(255) DEFAULT NULL,
    video_reply_url VARCHAR(255) DEFAULT NULL,
    INDEX IDX_FB_COMMENT_AUTO_RULE_CAMP (automation_campaign_id),
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

ALTER TABLE `facebook_connection`
    ADD CONSTRAINT FK_262F40D84AA2A339 FOREIGN KEY (active_context_id) REFERENCES ai_context (id) ON DELETE SET NULL;

ALTER TABLE `message_template`
    ADD CONSTRAINT FK_9E46DB92C664C80F FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE;

ALTER TABLE `subscriber`
    ADD CONSTRAINT FK_AD005B696381BF43 FOREIGN KEY (whats_app_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_SUBSCRIBER_FB_CONN FOREIGN KEY (facebook_connection_id) REFERENCES facebook_connection (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_SUBSCRIBER_OWNER FOREIGN KEY (owner_id) REFERENCES admin (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_AD005B697F7F786A FOREIGN KEY (assigned_operator_id) REFERENCES `admin` (id) ON DELETE SET NULL,
    ADD CONSTRAINT FK_AD005B6942F7871F FOREIGN KEY (assigned_whatsapp_flow_id) REFERENCES whatsapp_bot_flow (id),
    ADD CONSTRAINT FK_AD005B69C9CE08D1 FOREIGN KEY (assigned_facebook_flow_id) REFERENCES facebook_bot_flow (id) ON DELETE SET NULL;

ALTER TABLE `facebook_drip_sequence`
    ADD CONSTRAINT FK_FD73AE22BE73D3C2 FOREIGN KEY (facebook_connection_id) REFERENCES facebook_connection (id) ON DELETE CASCADE;

ALTER TABLE `whatsapp_drip_sequence`
    ADD CONSTRAINT FK_8564B5EFC664C80F FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE;

ALTER TABLE `facebook_action_button`
    ADD CONSTRAINT FK_4B957195BE73D3C2 FOREIGN KEY (facebook_connection_id) REFERENCES facebook_connection (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_4B957195AD962686 FOREIGN KEY (facebook_bot_flow_id) REFERENCES facebook_bot_flow (id) ON DELETE SET NULL;

ALTER TABLE `whatsapp_action_button`
    ADD CONSTRAINT FK_33826A586381BF43 FOREIGN KEY (whats_app_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_33826A58B2B4395F FOREIGN KEY (whats_app_bot_flow_id) REFERENCES whatsapp_bot_flow (id) ON DELETE SET NULL;

ALTER TABLE `facebook_comment_automation`
    ADD CONSTRAINT FK_FB_COMMENT_AUTO_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_FB_COMMENT_AUTO_CONN FOREIGN KEY (facebook_connection_id) REFERENCES facebook_connection (id) ON DELETE CASCADE;

ALTER TABLE `facebook_comment_automation_rule`
    ADD CONSTRAINT FK_FB_COMMENT_AUTO_RULE_CAMP FOREIGN KEY (automation_campaign_id) REFERENCES facebook_comment_automation (id) ON DELETE CASCADE;

-- ───── Workspace Owner Constraints ─────
ALTER TABLE `ai_context`
    ADD CONSTRAINT FK_AI_CONTEXT_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `ai_setting`
    ADD CONSTRAINT FK_AI_SETTING_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `facebook_setting`
    ADD CONSTRAINT FK_E94FA4E37E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `whatsapp_connection`
    ADD CONSTRAINT FK_WHATSAPP_CONN_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `facebook_connection`
    ADD CONSTRAINT FK_FACEBOOK_CONN_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `message_template`
    ADD CONSTRAINT FK_MSG_TEMP_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `facebook_bot_flow`
    ADD CONSTRAINT FK_14C7C9AA7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_14C7C9AABE73D3C2 FOREIGN KEY (facebook_connection_id) REFERENCES facebook_connection (id) ON DELETE CASCADE;

ALTER TABLE `whatsapp_bot_flow`
    ADD CONSTRAINT FK_68901F927E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_68901F92C664C80F FOREIGN KEY (whatsapp_connection_id) REFERENCES whatsapp_connection (id) ON DELETE CASCADE;

ALTER TABLE `facebook_drip_sequence`
    ADD CONSTRAINT FK_FD73AE227E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `whatsapp_drip_sequence`
    ADD CONSTRAINT FK_8564B5EF7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `facebook_action_button`
    ADD CONSTRAINT FK_4B9571957E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

ALTER TABLE `whatsapp_action_button`
    ADD CONSTRAINT FK_33826A587E3C61F9 FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE;

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
    ('DoctrineMigrations\\Version20260520093159', NOW(), 82),
    ('DoctrineMigrations\\Version20260521094438', NOW(), 100),
    ('DoctrineMigrations\\Version20260521102506', NOW(), 58),
    ('DoctrineMigrations\\Version20260523070711', NOW(), 45);

SET FOREIGN_KEY_CHECKS = 1;
