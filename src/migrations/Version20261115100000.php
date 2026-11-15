<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Phase 8: optional task effort estimate in minutes, and an index for per-user time reports by work date. */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Task estimates and time-entry user/date index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD estimated_minutes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT chk_task_estimated_minutes CHECK (estimated_minutes IS NULL OR (estimated_minutes >= 1 AND estimated_minutes <= 6000000))');
        $this->addSql('CREATE INDEX idx_time_entry_user_date ON time_entry (user_id, work_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_time_entry_user_date');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT chk_task_estimated_minutes');
        $this->addSql('ALTER TABLE task DROP estimated_minutes');
    }
}
