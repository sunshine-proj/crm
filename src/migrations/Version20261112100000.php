<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the journal source index made redundant by the unique constraint on the same columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_journal_entry_source');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_journal_entry_source ON journal_entry (source_type, source_id)');
    }
}
