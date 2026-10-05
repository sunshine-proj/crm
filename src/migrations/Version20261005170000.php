<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove redundant single-column goods receipt indexes covered by composite indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_B6CFD848A45D7E6A');
        $this->addSql('DROP INDEX IDX_B6CFD8485080ECDE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_B6CFD848A45D7E6A ON goods_receipt (purchase_order_id)');
        $this->addSql('CREATE INDEX IDX_B6CFD8485080ECDE ON goods_receipt (warehouse_id)');
    }
}