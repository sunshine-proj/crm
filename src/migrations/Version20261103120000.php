<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add structured client legal and account details.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD legal_name VARCHAR(255) DEFAULT NULL, ADD registration_number VARCHAR(64) DEFAULT NULL, ADD vat_number VARCHAR(32) DEFAULT NULL, ADD country_code VARCHAR(2) DEFAULT NULL, ADD billing_address TEXT DEFAULT NULL, ADD billing_email VARCHAR(180) DEFAULT NULL, ADD website VARCHAR(255) DEFAULT NULL, ADD industry VARCHAR(120) DEFAULT NULL, ADD account_status VARCHAR(16) DEFAULT \'active\' NOT NULL');
        $this->addSql('CREATE INDEX idx_client_account_status ON client (account_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_client_account_status');
        $this->addSql('ALTER TABLE client DROP legal_name, DROP registration_number, DROP vat_number, DROP country_code, DROP billing_address, DROP billing_email, DROP website, DROP industry, DROP account_status');
    }
}
