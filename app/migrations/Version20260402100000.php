<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260402100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona coluna numero (opcional) à tabela pasta_documento';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_documento ADD numero VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_documento DROP COLUMN numero');
    }
}
