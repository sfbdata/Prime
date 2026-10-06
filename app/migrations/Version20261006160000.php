<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Administrativo sem processo" (Trilha B, inventário da Pasta §5, lote L10): `pasta.administrativa`
 * marca a pasta de atuação administrativa (consultoria, extrajudicial, contrato), que não terá
 * processo judicial. Interruptor no cabeçalho da aba Processo (desenho 1.2.3).
 *
 * Escrita à mão (worktree sem container). `BOOLEAN DEFAULT false NOT NULL` é a forma que o
 * Doctrine gera para `#[ORM\Column(options: ['default' => false])] bool` no PostgreSQL — a mesma
 * de `pasta.pro_bono`. O DEFAULT preenche todo o acervo existente com "não marcada" na hora,
 * sem UPDATE em lote. Nenhum índice: a coluna não é filtro de listagem nesta entrega.
 */
final class Version20261006160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona pasta.administrativa ("Administrativo sem processo", default false)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta ADD administrativa BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta DROP administrativa');
    }
}
