<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260827130500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align the news item user index with Doctrine metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_873a3c3ea76ed395 RENAME TO IDX_CAC6D395A76ED395');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX IDX_CAC6D395A76ED395 RENAME TO idx_873a3c3ea76ed395');
    }
}