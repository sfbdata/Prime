<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tarefa\TarefaMensagem;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * As consultas (SQL cru, só leitura) da limpeza de cópias repetidas de documentos
 * (`app:documentos:apagar-duplicados`): os grupos de mesmo conteúdo DENTRO de uma pasta e o que
 * prende cada documento (vínculo) a outra parte do sistema.
 *
 * Critério único: o MESMO `sha256` na MESMA pasta. O critério "provável" (nome + tamanho) do
 * relatório de duplicados do conector NUNCA entra aqui: é indício, não prova. Documento na lixeira,
 * pasta com lápide e arquivo de 0 byte ficam de fora. SQL cru não passa pelo `LixeiraFilter`: o
 * `excluido_em IS NULL` e o escopo do escritório (nos dois lados do JOIN) são escritos à mão.
 */
class ConsultaDeDuplicadosDaPasta
{
    private const ANEXO_DE_TAREFA = 'anexo_de_tarefa';

    public function __construct(private readonly Connection $conexao)
    {
    }

    /**
     * Os grupos repetidos, por pasta crescente. `$limite` conta GRUPOS, mas a última pasta vem
     * inteira (o cursor `$aPartirDe` é por pasta: cortar no meio dela perderia os grupos restantes).
     * `ids` e `tamanhos` vêm do documento mais antigo (`uploaded_at`, `id`) ao mais novo.
     *
     * @return list<array{tenant_id: int, pasta_id: int, sha256: string, ids: list<int>, tamanhos: list<int>}>
     */
    public function grupos(?int $tenantId, ?int $pastaId, ?int $aPartirDe, ?int $limite): array
    {
        $recorte = $limite === null ? '' : ' WHERE pasta_id <= COALESCE((SELECT pasta_id FROM g ORDER BY pasta_id, sha256 OFFSET :corte LIMIT 1), pasta_id)';
        $sql     = $this->sqlDosGrupos($tenantId, $pastaId) . ' SELECT * FROM g' . $recorte . ' ORDER BY pasta_id, sha256';

        $parametros = $this->parametros($tenantId, $pastaId, $aPartirDe ?? 0);
        if ($limite !== null) {
            $parametros['corte'] = $limite - 1;
        }

        $grupos = [];
        foreach ($this->conexao->fetchAllAssociative($sql, $parametros) as $linha) {
            $grupos[] = [
                'tenant_id' => (int) $linha['tenant_id'],
                'pasta_id'  => (int) $linha['pasta_id'],
                'sha256'    => (string) $linha['sha256'],
                'ids'       => array_map('intval', explode(',', (string) $linha['ids'])),
                'tamanhos'  => array_map('intval', explode(',', (string) $linha['tamanhos'])),
            ];
        }

        return $grupos;
    }

    /** Quantos grupos ainda restam em pastas DEPOIS de `$depoisDaPasta` (o "a frente" do cursor). */
    public function gruposDepoisDaPasta(?int $tenantId, ?int $pastaId, int $depoisDaPasta): int
    {
        $sql = $this->sqlDosGrupos($tenantId, $pastaId) . ' SELECT COUNT(*) FROM g';

        return (int) $this->conexao->fetchOne($sql, $this->parametros($tenantId, $pastaId, $depoisDaPasta));
    }

    /**
     * O que prende cada documento: id => lista de motivos (ausente = sem vínculo). Os vínculos:
     * assinatura eletrônica, documento do cliente, favorito, uso em modelo de peça, anexo de mensagem
     * de tarefa (`pasta_documento:<id>`) e o selo "Assinado" do próprio documento (contrato assinado
     * pelo arquivo). Nenhum deles é repontado pela limpeza: por isso a cópia que tem vínculo é a que fica.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<string>>
     */
    public function vinculos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $anexos = array_map(static fn (int $id): string => TarefaMensagem::PREFIXO_DOCUMENTO_DA_PASTA . $id, $ids);
        $porId  = [];

        foreach ($this->consultasDeVinculo() as $motivo => $sql) {
            $anexo = $motivo === self::ANEXO_DE_TAREFA;
            $achou = $this->conexao->fetchFirstColumn($sql, ['ids' => $anexo ? $anexos : $ids], ['ids' => $anexo ? ArrayParameterType::STRING : ArrayParameterType::INTEGER]);
            foreach ($achou as $valor) {
                $id          = $anexo ? (int) substr((string) $valor, \strlen(TarefaMensagem::PREFIXO_DOCUMENTO_DA_PASTA)) : (int) $valor;
                $porId[$id][] = $motivo;
            }
        }

        return $porId;
    }

    /** @return array<string, string> motivo => SQL que devolve uma coluna (o id, ou o anexo) */
    private function consultasDeVinculo(): array
    {
        return [
            'assinatura'            => 'SELECT pasta_documento_id FROM assinatura_eletronica_documento WHERE pasta_documento_id IN (:ids)',
            'cliente_documento'     => 'SELECT pasta_documento_id FROM cliente_documento WHERE pasta_documento_id IN (:ids)',
            'favorito'              => 'SELECT documento_id FROM pasta_documento_favorito WHERE documento_id IN (:ids)',
            'modelo_de_peca'        => 'SELECT documento_id FROM modelo_de_peca_uso WHERE documento_id IN (:ids)',
            self::ANEXO_DE_TAREFA   => 'SELECT arquivo_anexo FROM tarefa_mensagem WHERE arquivo_anexo IN (:ids)',
            'assinado'              => 'SELECT id FROM pasta_documento WHERE id IN (:ids) AND assinado_em IS NOT NULL',
        ];
    }

    /** @return array<string, int> */
    private function parametros(?int $tenantId, ?int $pastaId, int $depoisDaPasta): array
    {
        $parametros = ['depois' => $depoisDaPasta];
        if ($tenantId !== null) {
            $parametros['tenant'] = $tenantId;
        }
        if ($pastaId !== null) {
            $parametros['pasta'] = $pastaId;
        }

        return $parametros;
    }

    /** A CTE `g`: um grupo por (pasta, sha256) com 2 ou mais documentos vivos. */
    private function sqlDosGrupos(?int $tenantId, ?int $pastaId): string
    {
        $doTenant = $tenantId !== null ? ' AND d.tenant_id = :tenant AND p.tenant_id = :tenant' : '';
        $daPasta  = $pastaId !== null ? ' AND d.pasta_id = :pasta' : '';

        return <<<SQL
            WITH g AS (
                SELECT d.tenant_id, d.pasta_id, d.sha256::text AS sha256,
                       string_agg(d.id::text, ',' ORDER BY d.uploaded_at, d.id) AS ids,
                       string_agg(d.tamanho_bytes::text, ',' ORDER BY d.uploaded_at, d.id) AS tamanhos
                  FROM pasta_documento d
                  JOIN pasta p ON p.id = d.pasta_id AND p.tenant_id = d.tenant_id
                 WHERE d.sha256 IS NOT NULL AND d.excluido_em IS NULL AND p.excluida_em IS NULL
                   AND d.tamanho_bytes > 0 AND d.pasta_id > :depois{$doTenant}{$daPasta}
                 GROUP BY d.tenant_id, d.pasta_id, d.sha256
                HAVING COUNT(*) > 1
            )
            SQL;
    }
}
