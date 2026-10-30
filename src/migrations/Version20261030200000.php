<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add finance account mappings, auditable fiscal period controls, and idempotent posting source constraints.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization_settings ADD accounts_receivable_account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE organization_settings ADD revenue_account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE organization_settings ADD cash_account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE organization_settings ADD accounts_payable_account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE organization_settings ADD project_expense_account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE organization_settings ADD expense_payable_account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE organization_settings ADD CONSTRAINT fk_org_settings_ar FOREIGN KEY (accounts_receivable_account_id) REFERENCES ledger_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE organization_settings ADD CONSTRAINT fk_org_settings_revenue FOREIGN KEY (revenue_account_id) REFERENCES ledger_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE organization_settings ADD CONSTRAINT fk_org_settings_cash FOREIGN KEY (cash_account_id) REFERENCES ledger_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE organization_settings ADD CONSTRAINT fk_org_settings_ap FOREIGN KEY (accounts_payable_account_id) REFERENCES ledger_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE organization_settings ADD CONSTRAINT fk_org_settings_expense FOREIGN KEY (project_expense_account_id) REFERENCES ledger_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE organization_settings ADD CONSTRAINT fk_org_settings_expense_payable FOREIGN KEY (expense_payable_account_id) REFERENCES ledger_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_org_settings_ar ON organization_settings (accounts_receivable_account_id)');
        $this->addSql('CREATE INDEX idx_org_settings_revenue ON organization_settings (revenue_account_id)');
        $this->addSql('CREATE INDEX idx_org_settings_cash ON organization_settings (cash_account_id)');
        $this->addSql('CREATE INDEX idx_org_settings_ap ON organization_settings (accounts_payable_account_id)');
        $this->addSql('CREATE INDEX idx_org_settings_expense ON organization_settings (project_expense_account_id)');
        $this->addSql('CREATE INDEX idx_org_settings_expense_payable ON organization_settings (expense_payable_account_id)');

        $this->addSql('ALTER TABLE fiscal_period ADD closed_by VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE fiscal_period ADD closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE fiscal_period ADD close_reason VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE fiscal_period ADD reopened_by VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE fiscal_period ADD reopened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE fiscal_period ADD reopen_reason VARCHAR(500) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_journal_entry_source ON journal_entry (source_type, source_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_journal_entry_source');
        $this->addSql('ALTER TABLE fiscal_period DROP closed_by, DROP closed_at, DROP close_reason, DROP reopened_by, DROP reopened_at, DROP reopen_reason');
        $this->addSql('ALTER TABLE organization_settings DROP CONSTRAINT fk_org_settings_ar, DROP CONSTRAINT fk_org_settings_revenue, DROP CONSTRAINT fk_org_settings_cash, DROP CONSTRAINT fk_org_settings_ap, DROP CONSTRAINT fk_org_settings_expense, DROP CONSTRAINT fk_org_settings_expense_payable');
        $this->addSql('DROP INDEX idx_org_settings_ar');
        $this->addSql('DROP INDEX idx_org_settings_revenue');
        $this->addSql('DROP INDEX idx_org_settings_cash');
        $this->addSql('DROP INDEX idx_org_settings_ap');
        $this->addSql('DROP INDEX idx_org_settings_expense');
        $this->addSql('DROP INDEX idx_org_settings_expense_payable');
        $this->addSql('ALTER TABLE organization_settings DROP accounts_receivable_account_id, DROP revenue_account_id, DROP cash_account_id, DROP accounts_payable_account_id, DROP project_expense_account_id, DROP expense_payable_account_id');
    }
}
