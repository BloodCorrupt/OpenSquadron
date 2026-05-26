<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260526141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ecom_product and ecom_order tables for the eCommerce Hub';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `ecom_product` (
            id INT AUTO_INCREMENT NOT NULL,
            owner_id INT DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            price NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) NOT NULL DEFAULT \'USD\',
            sku VARCHAR(100) DEFAULT NULL,
            image_url VARCHAR(500) DEFAULT NULL,
            gallery_urls JSON DEFAULT NULL,
            category VARCHAR(255) DEFAULT NULL,
            stock INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT \'active\',
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX IDX_ECOM_PRODUCT_OWNER (owner_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_ECOM_PRODUCT_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE `ecom_order` (
            id INT AUTO_INCREMENT NOT NULL,
            owner_id INT DEFAULT NULL,
            order_number VARCHAR(50) NOT NULL,
            subscriber_id INT DEFAULT NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_contact VARCHAR(255) DEFAULT NULL,
            channel VARCHAR(20) DEFAULT NULL,
            items JSON NOT NULL,
            total_amount NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) NOT NULL DEFAULT \'USD\',
            status VARCHAR(30) NOT NULL DEFAULT \'pending\',
            shipping_address LONGTEXT DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX IDX_ECOM_ORDER_OWNER (owner_id),
            INDEX IDX_ECOM_ORDER_SUBSCRIBER (subscriber_id),
            UNIQUE INDEX UNIQ_ECOM_ORDER_NUMBER (order_number),
            PRIMARY KEY(id),
            CONSTRAINT FK_ECOM_ORDER_OWNER FOREIGN KEY (owner_id) REFERENCES `admin` (id) ON DELETE CASCADE,
            CONSTRAINT FK_ECOM_ORDER_SUBSCRIBER FOREIGN KEY (subscriber_id) REFERENCES subscriber (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `ecom_order`');
        $this->addSql('DROP TABLE `ecom_product`');
    }
}
