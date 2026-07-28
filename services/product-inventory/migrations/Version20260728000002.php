<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Схема catalog + таблиця products. */
final class Version20260728000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog schema and products table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS catalog');
        $this->addSql(<<<'SQL'
            CREATE TABLE catalog.products (
                id VARCHAR(36) NOT NULL,
                sku VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                price_amount_minor BIGINT NOT NULL,
                price_currency VARCHAR(3) NOT NULL,
                stock_available INT NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_product_sku ON catalog.products (sku)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog.products');
    }
}
