<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use Doctrine\DBAL\Connection;

/**
 * Quantos bytes um escritório guarda, somando os arquivos que têm tamanho gravado no banco.
 *
 * Mesmas tabelas e mesma regra do `Master\Repository\PainelMasterRepository::TABELAS_DE_ARQUIVO`
 * (o número do painel e o do bloqueio têm de bater):
 *  - documento de pasta na lixeira (`excluido_em`) não conta; documento de pasta em lápide conta,
 *    porque o arquivo continua guardado;
 *  - justificativa de ponto, anexo de tarefa, imagem do editor e foto de perfil não têm tamanho no
 *    banco e ficam fora (a foto é global, do User);
 *  - documento do Trabalho Externo fica fora: pertence a uma demanda entre escritórios, sem dono
 *    único para cobrar o espaço.
 *
 * DBAL e não DQL: é uma soma sobre sete tabelas de domínios diferentes, sem entidade a hidratar, e o
 * filtro de tenant vai explícito em cada parte (o TenantFilter não alcança SQL cru).
 */
// Não-final: os testes do decorador mockam o repositório.
class ConsumoDeArmazenamentoRepository
{
    /** [tabela, coluna do tamanho, condição do tenant, filtro extra] */
    private const TABELAS_DE_ARQUIVO = [
        ['pasta_documento', 'tamanho_bytes', 'x.tenant_id = :tenant', 'x.excluido_em IS NULL'],
        ['cliente_documento', 'tamanho_bytes', 'x.tenant_id = :tenant', null],
        ['cobranca_documento', 'tamanho_bytes', 'x.tenant_id = :tenant', null],
        ['cobranca_carteira_documento', 'tamanho_bytes', 'x.tenant_id = :tenant', null],
        ['cobranca_acordo_documento', 'tamanho_bytes', 'x.tenant_id = :tenant', null],
        ['chamado_anexo', 'tamanho', 'x.chamado_id IN (SELECT c.id FROM chamado c WHERE c.tenant_id = :tenant)', null],
        ['kanban_anexo', 'tamanho', 'x.tenant_id = :tenant', null],
    ];

    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    public function bytesDoEscritorio(int $tenantId): int
    {
        return (int) $this->conexao->fetchOne(
            'SELECT COALESCE(SUM(a.bytes), 0) FROM (' . self::arquivosDoEscritorio() . ') a',
            ['tenant' => $tenantId],
        );
    }

    private static function arquivosDoEscritorio(): string
    {
        $partes = [];
        foreach (self::TABELAS_DE_ARQUIVO as [$tabela, $tamanho, $tenant, $filtro]) {
            $partes[] = sprintf(
                'SELECT COALESCE(x.%s, 0)::bigint AS bytes FROM %s x WHERE %s%s',
                $tamanho,
                $tabela,
                $tenant,
                $filtro !== null ? ' AND ' . $filtro : '',
            );
        }

        return implode(' UNION ALL ', $partes);
    }
}
