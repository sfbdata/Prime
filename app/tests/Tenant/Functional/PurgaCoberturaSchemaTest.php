<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Tenant\UseCase\PurgarEscritorioUseCase;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guard-rail contra drift de schema: toda tabela com coluna tenant_id precisa ser tratada
 * pela purga — apagada explicitamente (ORDEM_DELECAO), retida por decisão (TABELAS_RETIDAS)
 * ou derrubada por CASCADE do banco a partir de uma raiz (allowlist abaixo). Se alguém
 * criar uma entidade tenant-aware nova e esquecer de cobrir a purga, este teste falha
 * ANTES de a purga rodar em produção com uma tabela órfã.
 */
final class PurgaCoberturaSchemaTest extends KernelTestCase
{
    /**
     * Tabelas com tenant_id que NÃO estão na ORDEM_DELECAO porque caem por CASCADE do banco
     * ao apagar a raiz do seu subsistema. Verificado no grafo de FKs (ON DELETE CASCADE).
     */
    private const COBERTAS_POR_CASCATA = [
        'documento_processo',            // ← processo
        'pasta_secao',                   // ← pasta
        'pasta_checklist_item',          // ← pasta
        'pasta_mensagem',                // ← pasta
        'pasta_observacao_detalhes',     // ← pasta
        'pasta_observacao_financeira',   // ← pasta
        'pasta_pagamento',               // ← pasta (delete_rule CASCADE conferido no banco)
        'pasta_checklist_modelo_item',   // ← pasta_checklist_modelo (que está na ORDEM_DELECAO)
        'tarefa_mensagem',               // ← tarefa
        'cobranca_pessoa_endereco',      // ← cobranca_pessoa
        'cobranca_pessoa_telefone',      // ← cobranca_pessoa
        'cobranca_pessoa_email',         // ← cobranca_pessoa

        // Kanban: ganharam tenant_id na fatia 2 de docs/specs/isolamento-multitenant-cobertura.md.
        // Todas caem por CASCADE até kanban_board, que já está na ORDEM_DELECAO. Grafo de FKs
        // conferido no banco (delete_rule = CASCADE em cada elo), não só no mapeamento:
        //   coluna/marcador → board · card → coluna e board · checklist/comentario/anexo → card
        //   checklist_item → checklist
        'kanban_coluna',
        'kanban_marcador',
        'kanban_card',
        'kanban_checklist',
        'kanban_checklist_item',
        'kanban_comentario',
        'kanban_anexo',
    ];

    #[TestDox('Toda tabela com tenant_id é coberta pela purga (ordem, retenção ou cascata)')]
    public function testTodaTabelaTenantScopedEstaCoberta(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        \assert($conn instanceof Connection);

        $comTenantId = $conn->fetchFirstColumn(
            "SELECT table_name FROM information_schema.columns
             WHERE table_schema = 'public' AND column_name = 'tenant_id' ORDER BY table_name",
        );

        $ref            = new \ReflectionClass(PurgarEscritorioUseCase::class);
        $ordemDelecao   = array_column($ref->getConstant('ORDEM_DELECAO'), 0);
        $retidas        = $ref->getConstant('TABELAS_RETIDAS');
        $cobertas       = array_merge($ordemDelecao, $retidas, self::COBERTAS_POR_CASCATA);

        $naoCobertas = array_values(array_diff($comTenantId, $cobertas));

        self::assertSame(
            [],
            $naoCobertas,
            'Tabela(s) com tenant_id fora do tratamento da purga: ' . implode(', ', $naoCobertas)
            . '. Adicione à ORDEM_DELECAO, a TABELAS_RETIDAS ou (se cai por cascata) à allowlist deste teste.',
        );
    }

    #[TestDox('Toda tabela-ponte SEM tenant_id que bloqueia a purga (FK NO ACTION) está na ordem de deleção')]
    public function testTabelasPonteSemTenantIdEstaoNaOrdem(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        \assert($conn instanceof Connection);

        $ref          = new \ReflectionClass(PurgarEscritorioUseCase::class);
        $ordemDelecao = array_column($ref->getConstant('ORDEM_DELECAO'), 0);
        $universo     = array_merge($ordemDelecao, self::COBERTAS_POR_CASCATA, ['tenant']);

        // Filhas com FK NO ACTION/RESTRICT para uma tabela do universo da purga e SEM coluna
        // tenant_id: são "pontes" que bloqueiam o DELETE do pai e o guard runtime (que só varre
        // tenant_id) não pega. Precisam estar explicitamente na ORDEM_DELECAO (via subquery).
        $pontes = $conn->fetchFirstColumn(
            "SELECT DISTINCT filha.relname
             FROM pg_constraint c
             JOIN pg_class filha ON filha.oid = c.conrelid
             JOIN pg_class pai ON pai.oid = c.confrelid
             WHERE c.contype = 'f' AND c.confdeltype IN ('a', 'r')
               AND pai.relname IN (:universo)
               AND NOT EXISTS (
                 SELECT 1 FROM information_schema.columns col
                 WHERE col.table_name = filha.relname AND col.column_name = 'tenant_id')",
            ['universo' => $universo],
            ['universo' => ArrayParameterType::STRING],
        );

        $naoCobertas = array_values(array_diff($pontes, $ordemDelecao));

        self::assertSame(
            [],
            $naoCobertas,
            'Tabela-ponte sem tenant_id fora da ORDEM_DELECAO: ' . implode(', ', $naoCobertas)
            . '. Adicione-a à ORDEM_DELECAO (com subquery pelo pai) antes de apagar o pai.',
        );
    }
}
