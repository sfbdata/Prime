<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Push Processual (desenho 11): "Excluir" e "Limpar lidas" tiram a publicação da caixa do Push sem
 * apagar a linha. A exclusão é lógica porque a sincronização com o DJEN reimporta por
 * (tenant_id, djen_id); sem a linha, a publicação excluída voltaria na próxima captação.
 */
final class Version20261027152400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Push: publicacao_djen ganha excluida_em (exclusão lógica da caixa do Push)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publicacao_djen ADD excluida_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Os índices do PR dos processos acompanhados saíram com nome diferente do que o mapeamento espera.
        $this->addSql('ALTER INDEX IF EXISTS idx_djen_proc_acomp_tenant RENAME TO IDX_7A71620B9033212A');
        $this->addSql('ALTER INDEX IF EXISTS idx_djen_proc_acomp_criador RENAME TO IDX_7A71620BF42F4A03');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX IF EXISTS IDX_7A71620B9033212A RENAME TO idx_djen_proc_acomp_tenant');
        $this->addSql('ALTER INDEX IF EXISTS IDX_7A71620BF42F4A03 RENAME TO idx_djen_proc_acomp_criador');
        $this->addSql('ALTER TABLE publicacao_djen DROP excluida_em');
    }
}
