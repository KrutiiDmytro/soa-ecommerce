<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Схема orders + таблиці кошика та замовлення. */
final class Version20260730000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create orders schema with carts, cart_items, orders and order_lines tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS orders');

        $this->addSql(<<<'SQL'
            CREATE TABLE orders.carts (
                id VARCHAR(36) NOT NULL,
                customer_id VARCHAR(36) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE orders.cart_items (
                id VARCHAR(36) NOT NULL,
                cart_id VARCHAR(36) NOT NULL,
                product_id VARCHAR(36) NOT NULL,
                sku VARCHAR(64) NOT NULL,
                quantity INT NOT NULL,
                unit_price_amount_minor BIGINT NOT NULL,
                unit_price_currency VARCHAR(3) NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_cart_item_cart FOREIGN KEY (cart_id) REFERENCES orders.carts (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_cart_item_cart ON orders.cart_items (cart_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE orders.orders (
                id VARCHAR(36) NOT NULL,
                customer_id VARCHAR(36) NOT NULL,
                status VARCHAR(16) NOT NULL,
                total_amount_minor BIGINT NOT NULL,
                total_currency VARCHAR(3) NOT NULL,
                shipping_line1 VARCHAR(255) NOT NULL,
                shipping_line2 VARCHAR(255) DEFAULT NULL,
                shipping_city VARCHAR(128) NOT NULL,
                shipping_postal_code VARCHAR(32) NOT NULL,
                shipping_country VARCHAR(2) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_order_customer ON orders.orders (customer_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE orders.order_lines (
                id VARCHAR(36) NOT NULL,
                order_id VARCHAR(36) NOT NULL,
                product_id VARCHAR(36) NOT NULL,
                sku VARCHAR(64) NOT NULL,
                quantity INT NOT NULL,
                unit_price_amount_minor BIGINT NOT NULL,
                unit_price_currency VARCHAR(3) NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_order_line_order FOREIGN KEY (order_id) REFERENCES orders.orders (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_order_line_order ON orders.order_lines (order_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE orders.order_lines');
        $this->addSql('DROP TABLE orders.orders');
        $this->addSql('DROP TABLE orders.cart_items');
        $this->addSql('DROP TABLE orders.carts');
    }
}
