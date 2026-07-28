<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Схема customer + таблиця customers. */
final class Version20260728000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create customer schema and customers table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS customer');
        $this->addSql(<<<'SQL'
            CREATE TABLE customer.customers (
                id VARCHAR(36) NOT NULL,
                email VARCHAR(255) NOT NULL,
                full_name VARCHAR(255) DEFAULT NULL,
                password_hash VARCHAR(255) NOT NULL,
                addresses JSON NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_customer_email ON customer.customers (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE customer.customers');
    }
}
