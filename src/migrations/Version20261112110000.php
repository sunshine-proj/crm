<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link record revisions written for database cascades to the deleted parent record.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE record_revision ADD cause_type VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE record_revision ADD cause_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_record_revision_cause ON record_revision (cause_type, cause_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_record_revision_cause');
        $this->addSql('ALTER TABLE record_revision DROP cause_type');
        $this->addSql('ALTER TABLE record_revision DROP cause_id');
    }
}
