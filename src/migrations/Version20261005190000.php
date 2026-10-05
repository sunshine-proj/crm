<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align project foreign-key index names for operations tables with Doctrine metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER INDEX IDX_E52701AD166D1F9C RENAME TO idx_project_document_project');
        $this->addSql('ALTER INDEX IDX_2D3A8DA6166D1F9C RENAME TO idx_expense_project');
        $this->addSql('ALTER INDEX IDX_D08157D2F395DB7B RENAME TO idx_expense_approval_expense');
        $this->addSql('ALTER INDEX IDX_6E537C0C166D1F9C RENAME TO idx_time_entry_project');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_project_document_project RENAME TO IDX_E52701AD166D1F9C');
        $this->addSql('ALTER INDEX idx_expense_project RENAME TO IDX_2D3A8DA6166D1F9C');
        $this->addSql('ALTER INDEX idx_expense_approval_expense RENAME TO IDX_D08157D2F395DB7B');
        $this->addSql('ALTER INDEX idx_time_entry_project RENAME TO IDX_6E537C0C166D1F9C');
    }
}