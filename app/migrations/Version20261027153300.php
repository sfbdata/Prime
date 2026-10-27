<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Push, "Excluir processo" (spec `docs/specs/push-excluir-processo.md`): `djen_processo_acompanhado.excluido_em`.
 * Número com a marca não volta ao Relatório da aba Processos da OAB. Escrita à mão a partir do mapeamento.
 */
final class Version20261027153300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Push: excluido_em em djen_processo_acompanhado';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE djen_processo_acompanhado ADD excluido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE djen_processo_acompanhado DROP excluido_em');
    }
}
