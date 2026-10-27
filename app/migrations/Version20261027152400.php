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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publicacao_djen DROP excluida_em');
    }
}
