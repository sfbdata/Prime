<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Central de Acompanhamento da Cobrança, Fatia 1 (spec `cobranca-central-acompanhamento.md` §9): índice
 * `(tenant_id, ocorrido_em)` em `cobranca_evento_historico`.
 *
 * Os índices existentes da tabela são `(tenant_id)`, `(caso_id)`, `(usuario_id)` e `(tenant_id, caso_id)`
 * — NENHUM serve para filtrar por faixa de data, que é o eixo de toda consulta da nova tela ("o que a
 * equipe fez no período"). Sem ele, a agregação vira scan da partição do tenant e piora com o uso diário.
 *
 * Aditiva e reversível: só cria índice, não toca dado nem estrutura de coluna.
 */
final class Version20260722190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cobranca: indice (tenant_id, ocorrido_em) para a Central de Acompanhamento';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_cobranca_evento_tenant_ocorrido ON cobranca_evento_historico (tenant_id, ocorrido_em)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_cobranca_evento_tenant_ocorrido');
    }
}
