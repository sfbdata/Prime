<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona data_inicio e valor_total na tabela contrato';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contrato ADD data_inicio DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE contrato ADD valor_total NUMERIC(12, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contrato DROP data_inicio');
        $this->addSql('ALTER TABLE contrato DROP valor_total');
    }
}
