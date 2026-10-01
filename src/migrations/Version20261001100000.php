<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connect projects and tasks to their parent records and add planning state.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE project ADD client_id INT DEFAULT NULL, ADD status VARCHAR(20) DEFAULT 'planned' NOT NULL, ADD start_date DATE DEFAULT NULL, ADD end_date DATE DEFAULT NULL");
        $this->addSql('CREATE INDEX IDX_PROJECT_CLIENT_ID ON project (client_id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_PROJECT_CLIENT FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("ALTER TABLE task ADD project_id INT DEFAULT NULL, ADD status VARCHAR(20) DEFAULT 'todo' NOT NULL, ADD priority VARCHAR(20) DEFAULT 'normal' NOT NULL, ADD due_date DATE DEFAULT NULL");
        $this->addSql('CREATE INDEX IDX_TASK_PROJECT_ID ON task (project_id)');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_TASK_PROJECT FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_TASK_PROJECT');
        $this->addSql('DROP INDEX IDX_TASK_PROJECT_ID');
        $this->addSql('ALTER TABLE task DROP project_id, DROP status, DROP priority, DROP due_date');

        $this->addSql('ALTER TABLE project DROP CONSTRAINT FK_PROJECT_CLIENT');
        $this->addSql('DROP INDEX IDX_PROJECT_CLIENT_ID');
        $this->addSql('ALTER TABLE project DROP client_id, DROP status, DROP start_date, DROP end_date');
    }
}