<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Схема fulfillment + таблиці платежів, відправлень і подій трекінгу. */
final class Version20260730000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create fulfillment schema with payments, shipments and tracking_events tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS fulfillment');

        $this->addSql(<<<'SQL'
            CREATE TABLE fulfillment.payments (
                id VARCHAR(36) NOT NULL,
                order_id VARCHAR(36) NOT NULL,
                amount_minor BIGINT NOT NULL,
                amount_currency VARCHAR(3) NOT NULL,
                status VARCHAR(16) NOT NULL,
                provider_ref VARCHAR(128) DEFAULT NULL,
                failure_reason VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_order ON fulfillment.payments (order_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE fulfillment.shipments (
                id VARCHAR(36) NOT NULL,
                order_id VARCHAR(36) NOT NULL,
                tracking_number VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                shipping_line1 VARCHAR(255) NOT NULL,
                shipping_line2 VARCHAR(255) DEFAULT NULL,
                shipping_city VARCHAR(128) NOT NULL,
                shipping_postal_code VARCHAR(32) NOT NULL,
                shipping_country VARCHAR(2) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_shipment_order ON fulfillment.shipments (order_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE fulfillment.tracking_events (
                id VARCHAR(36) NOT NULL,
                shipment_id VARCHAR(36) NOT NULL,
                status VARCHAR(16) NOT NULL,
                description VARCHAR(255) NOT NULL,
                sequence_no INT NOT NULL,
                occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fk_tracking_event_shipment FOREIGN KEY (shipment_id) REFERENCES fulfillment.shipments (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_tracking_event_shipment ON fulfillment.tracking_events (shipment_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fulfillment.tracking_events');
        $this->addSql('DROP TABLE fulfillment.shipments');
        $this->addSql('DROP TABLE fulfillment.payments');
    }
}
