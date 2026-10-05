<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002071235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_client_contact_client RENAME TO IDX_1E5FA24519EB6921');
        $this->renameIndexIfPresent($schema, 'invoice', 'idx_invoice_client', 'IDX_9065174419EB6921');
        $this->renameIndexIfPresent($schema, 'invoice', 'idx_invoice_project', 'IDX_90651744166D1F9C');
        $this->renameIndexIfPresent($schema, 'invoice', 'idx_invoice_manager', 'IDX_90651744783E3463');
        $this->addSql('ALTER INDEX idx_project_client_id RENAME TO IDX_2FB3D0EE19EB6921');
        $this->renameIndexIfPresent($schema, 'project', 'idx_project_manager', 'IDX_2FB3D0EE783E3463');
        $this->addSql('ALTER INDEX idx_task_project_id RENAME TO IDX_527EDB25166D1F9C');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_1e5fa24519eb6921 RENAME TO idx_client_contact_client');
        $this->renameIndexIfPresent($schema, 'invoice', 'idx_90651744783e3463', 'idx_invoice_manager');
        $this->renameIndexIfPresent($schema, 'invoice', 'idx_9065174419eb6921', 'idx_invoice_client');
        $this->renameIndexIfPresent($schema, 'invoice', 'idx_90651744166d1f9c', 'idx_invoice_project');
        $this->addSql('ALTER INDEX idx_2fb3d0ee19eb6921 RENAME TO idx_project_client_id');
        $this->renameIndexIfPresent($schema, 'project', 'idx_2fb3d0ee783e3463', 'idx_project_manager');
        $this->addSql('ALTER INDEX idx_527edb25166d1f9c RENAME TO idx_task_project_id');
    }

    private function renameIndexIfPresent(Schema $schema, string $tableName, string $oldName, string $newName): void
    {
        if ($schema->hasTable($tableName) && $schema->getTable($tableName)->hasIndex($oldName)) {
            $this->addSql(sprintf('ALTER INDEX %s RENAME TO %s', $oldName, $newName));
        }
    }
}
