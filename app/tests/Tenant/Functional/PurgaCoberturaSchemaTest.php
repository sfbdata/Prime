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
 *
 * A descoberta é a da própria purga ({@see PurgarEscritorioUseCase::SQL_TABELAS_COM_TENANT_ID}): todos os schemas
 * da aplicação, com o schema no nome fora do `public` (spec voto-redes-do-schema).
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
        $naoCobertas = $this->naoCobertas();

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
        $naoCobertas = $this->pontesForaDaOrdem();

        self::assertSame(
            [],
            $naoCobertas,
            'Tabela-ponte sem tenant_id fora da ORDEM_DELECAO: ' . implode(', ', $naoCobertas)
            . '. Adicione-a à ORDEM_DELECAO (com subquery pelo pai) antes de apagar o pai.',
        );
    }

    /**
     * E10: `ponto_ajuste` aponta (NO ACTION) para `justificativa_ponto` e `registro_ponto`; apagá-la depois de uma delas
     * quebraria a purga de todo escritório que já ajustou uma batida.
     */
    #[TestDox('Os ajustes de batida (ponto_ajuste) saem antes da justificativa e da batida gravada')]
    public function testAjustesDoPontoAntesDaJustificativaEDaBatida(): void
    {
        $ordem = array_column((new \ReflectionClass(PurgarEscritorioUseCase::class))->getConstant('ORDEM_DELECAO'), 0);

        self::assertContains('ponto_ajuste', $ordem);
        self::assertLessThan(array_search('justificativa_ponto', $ordem, true), array_search('ponto_ajuste', $ordem, true));
        self::assertLessThan(array_search('registro_ponto', $ordem, true), array_search('ponto_ajuste', $ordem, true));
    }

    /**
     * Prova 3 da spec voto-redes-do-schema: a rede olha o schema `voto`. A tabela nasce dentro da transação do DAMA e
     * sai no rollback.
     */
    #[TestDox('Tabela com tenant_id no schema voto, fora da purga, aparece como não coberta (com o schema no nome)')]
    public function testTabelaDoVotoForaDaPurgaApareceComoNaoCoberta(): void
    {
        $this->conexao()->executeStatement('CREATE TABLE voto._cobertura_teste (tenant_id int)');

        self::assertContains('voto._cobertura_teste', $this->naoCobertas());
    }

    #[TestDox('Filha sem tenant_id no schema voto que aponta para a voto.liberacao aparece como ponte')]
    public function testPonteNoSchemaVotoApareceComoPonte(): void
    {
        $this->conexao()->executeStatement('CREATE TABLE voto._ponte (pai_id int REFERENCES voto.liberacao(id))');

        self::assertContains('voto._ponte', $this->pontesForaDaOrdem());
    }

    private function conexao(): Connection
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        \assert($conn instanceof Connection);

        return $conn;
    }

    /**
     * Tabelas com tenant_id, pela MESMA descoberta da purga (todos os schemas da aplicação), fora da ordem, da
     * retenção e da allowlist de cascata.
     *
     * @return list<string>
     */
    private function naoCobertas(): array
    {
        $comTenantId = $this->conexao()->fetchFirstColumn(PurgarEscritorioUseCase::SQL_TABELAS_COM_TENANT_ID);

        $ref      = new \ReflectionClass(PurgarEscritorioUseCase::class);
        $cobertas = array_merge(array_column($ref->getConstant('ORDEM_DELECAO'), 0), $ref->getConstant('TABELAS_RETIDAS'), self::COBERTAS_POR_CASCATA);

        return array_values(array_diff($comTenantId, $cobertas));
    }

    /**
     * Filhas com FK NO ACTION/RESTRICT para uma tabela do universo da purga e SEM coluna tenant_id: são "pontes"
     * que bloqueiam o DELETE do pai e o guard runtime (que só varre tenant_id) não pega. Precisam estar
     * explicitamente na ORDEM_DELECAO (via subquery). Os dois lados levam o schema no nome (sem prefixo no
     * `public`, como na purga), e o tenant_id é procurado na própria filha (por oid), não por nome em qualquer schema.
     *
     * @return list<string>
     */
    private function pontesForaDaOrdem(): array
    {
        $ordemDelecao = array_column((new \ReflectionClass(PurgarEscritorioUseCase::class))->getConstant('ORDEM_DELECAO'), 0);

        $pontes = $this->conexao()->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT CASE WHEN nf.nspname = 'public' THEN filha.relname ELSE nf.nspname || '.' || filha.relname END
                  FROM pg_constraint k
                  JOIN pg_class filha ON filha.oid = k.conrelid
                  JOIN pg_namespace nf ON nf.oid = filha.relnamespace
                  JOIN pg_class pai ON pai.oid = k.confrelid
                  JOIN pg_namespace np ON np.oid = pai.relnamespace
                 WHERE k.contype = 'f' AND k.confdeltype IN ('a', 'r')
                   AND (CASE WHEN np.nspname = 'public' THEN pai.relname ELSE np.nspname || '.' || pai.relname END) IN (:universo)
                   AND NOT EXISTS (
                       SELECT 1 FROM pg_attribute a
                        WHERE a.attrelid = filha.oid AND a.attname = 'tenant_id' AND a.attnum > 0 AND NOT a.attisdropped)
                SQL,
            ['universo' => array_merge($ordemDelecao, self::COBERTAS_POR_CASCATA, ['tenant'])],
            ['universo' => ArrayParameterType::STRING],
        );

        return array_values(array_diff($pontes, $ordemDelecao));
    }
}
