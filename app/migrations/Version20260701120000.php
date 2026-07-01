<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Soft delete de escritório: coluna tenant.excluido_em (quarentena).
 *
 * Registra quando o escritório foi excluído. A exclusão passa a ser soft
 * (isActive=false + excluido_em), sem remover dados; a purga definitiva
 * fica para um job futuro.
 */
final class Version20260701120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tenant.excluido_em para soft delete (quarentena) de escritório';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant ADD excluido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP excluido_em');
    }
}
