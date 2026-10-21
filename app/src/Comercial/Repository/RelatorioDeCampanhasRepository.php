<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * As leituras do "Relatório" da Central de envios (desenho 05 VS 21.10.26, L300-305 e L502-514; spec
 * `comercial-relatorio.md`), por SQL na conexão DBAL, como o resto do Comercial. Só leitura: nada aqui grava.
 *
 * Plataforma, do Master (rota `ROLE_SUPER_ADMIN`): as tabelas não têm `tenant_id` e nenhuma consulta filtra por
 * escritório. As métricas de cada campanha somam os destinatários materializados (`comercial_campanha_destino`) com o
 * envio de cada um (`envio_id` → `comercial_envio`), em lote para a página inteira (uma consulta, sem N+1).
 *
 * As abas agrupam pelo estado: `rec` (recorrente), `ag` (agendada) e `env` (o resto: na fila, enviando, enviada,
 * cancelada e o `teste`, quando existir). A paginação é feita no SQL.
 *
 * @phpstan-type LinhaDaCampanha array{
 *     id: int, nome: string, assunto: string, titulo: string, modelo_nome: string, publico: string, frequencia: string,
 *     estado: string, total: int, execucoes: int, agendada_para: ?string, criada_em: string
 * }
 * @phpstan-type Metricas array{destinatarios: int, na_fila: int, enviados: int, entregues: int, lidos: int, rejeitados: int, clicaram: int}
 * @phpstan-type Destinatario array{email: string, situacao: ?string, aberto: bool, clicou: bool}
 */
class RelatorioDeCampanhasRepository
{
    /**
     * A regra de "entregue" (recebido), num lugar só: o envio saiu pelo SMTP sem erro. Não há retorno do servidor de
     * destino (bounce assíncrono) hoje; se o dono decidir outra regra, ela muda só aqui.
     */
    public const CONDICAO_ENTREGUE = "e.situacao = 'enviado' AND e.erro IS NULL";
    /** Enviados: o que tentou sair (saiu, foi recusado pelo SMTP ou ficou incerto). */
    public const SITUACOES_ENVIADAS = ['enviado', 'falhou', 'incerto'];
    /** Rejeitados: o SMTP recusou. */
    public const SITUACAO_REJEITADA = 'falhou';

    public const ABAS = ['env', 'ag', 'rec'];

    private const GRUPO = "CASE c.estado WHEN 'recorrente' THEN 'rec' WHEN 'agendada' THEN 'ag' ELSE 'env' END";

    public function __construct(private readonly Connection $conexao)
    {
    }

    /** @return array{env: int, ag: int, rec: int} quantas campanhas em cada aba (sem a busca, como o dc) */
    public function contarPorAba(): array
    {
        $linhas = $this->conexao->fetchAllKeyValue('SELECT ' . self::GRUPO . ' AS aba, COUNT(*) FROM comercial_campanha c GROUP BY 1');

        return ['env' => (int) ($linhas['env'] ?? 0), 'ag' => (int) ($linhas['ag'] ?? 0), 'rec' => (int) ($linhas['rec'] ?? 0)];
    }

    /** Quantas campanhas a aba tem com a busca (por nome, assunto, modelo ou e-mail de um destinatário). */
    public function contar(string $aba, string $busca): int
    {
        [$onde, $parametros] = self::filtro($aba, $busca);

        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_campanha c WHERE ' . $onde, $parametros);
    }

    /**
     * Uma página da aba, da mais nova à mais antiga.
     *
     * @return list<LinhaDaCampanha>
     */
    public function pagina(string $aba, string $busca, int $limite, int $deslocamento): array
    {
        [$onde, $parametros] = self::filtro($aba, $busca);
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT c.id, c.nome, c.assunto, c.titulo, c.modelo_nome, c.publico, c.frequencia, c.estado, c.total, c.execucoes,
                    c.agendada_para, c.criada_em
             FROM comercial_campanha c WHERE ' . $onde . ' ORDER BY c.id DESC LIMIT ' . max(1, $limite) . ' OFFSET ' . max(0, $deslocamento),
            $parametros,
        );

        return array_map(self::linha(...), $linhas);
    }

    /**
     * As métricas das campanhas dadas, numa consulta só: todos os destinatários de todas as execuções.
     *
     * @param list<int> $ids
     *
     * @return array<int, Metricas> por id de campanha (a que não tem destinatário não aparece)
     */
    public function metricas(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT d.campanha_id, COUNT(*) AS destinatarios,
                    COUNT(*) FILTER (WHERE d.envio_id IS NULL OR e.situacao IN (\'enfileirado\', \'enviando\')) AS na_fila,
                    COUNT(*) FILTER (WHERE e.situacao IN (:enviadas)) AS enviados,
                    COUNT(*) FILTER (WHERE ' . self::CONDICAO_ENTREGUE . ') AS entregues,
                    COUNT(*) FILTER (WHERE e.primeira_abertura_em IS NOT NULL) AS lidos,
                    COUNT(*) FILTER (WHERE e.situacao = :rejeitada) AS rejeitados,
                    COUNT(*) FILTER (WHERE e.primeiro_clique_em IS NOT NULL) AS clicaram
             FROM comercial_campanha_destino d LEFT JOIN comercial_envio e ON e.id = d.envio_id
             WHERE d.campanha_id IN (:ids) GROUP BY d.campanha_id',
            ['ids' => $ids, 'enviadas' => self::SITUACOES_ENVIADAS, 'rejeitada' => self::SITUACAO_REJEITADA],
            ['ids' => ArrayParameterType::INTEGER, 'enviadas' => ArrayParameterType::STRING],
        );
        $saida = [];
        foreach ($linhas as $l) {
            $saida[(int) $l['campanha_id']] = [
                'destinatarios' => (int) $l['destinatarios'], 'na_fila' => (int) $l['na_fila'], 'enviados' => (int) $l['enviados'],
                'entregues' => (int) $l['entregues'], 'lidos' => (int) $l['lidos'], 'rejeitados' => (int) $l['rejeitados'],
                'clicaram' => (int) $l['clicaram'],
            ];
        }

        return $saida;
    }

    /**
     * Os destinatários da campanha com o estado do envio, da execução mais nova para a mais antiga.
     *
     * @return list<Destinatario>
     */
    public function destinatarios(int $campanhaId, int $limite): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT d.email, e.situacao, e.primeira_abertura_em IS NOT NULL AS aberto, e.primeiro_clique_em IS NOT NULL AS clicou
             FROM comercial_campanha_destino d LEFT JOIN comercial_envio e ON e.id = d.envio_id
             WHERE d.campanha_id = :id ORDER BY d.execucao DESC, d.id LIMIT ' . max(1, $limite),
            ['id' => $campanhaId],
        );

        return array_map(static fn (array $l): array => [
            'email' => (string) $l['email'], 'situacao' => $l['situacao'] === null ? null : (string) $l['situacao'],
            'aberto' => (bool) $l['aberto'], 'clicou' => (bool) $l['clicou'],
        ], $linhas);
    }

    /** A campanha `enviada` que ainda tem envio esperando o worker (o EncerrarCampanha alcança, §8.1.5). */
    public function temEnvioNaFila(int $campanhaId): bool
    {
        return $this->conexao->fetchOne(
            "SELECT 1 FROM comercial_envio WHERE origem = :origem AND situacao IN ('enfileirado', 'enviando') LIMIT 1",
            ['origem' => 'campanha:' . $campanhaId],
        ) !== false;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private static function filtro(string $aba, string $busca): array
    {
        $onde = self::GRUPO . ' = :aba';
        $parametros = ['aba' => $aba];
        if ($busca !== '') {
            $onde .= " AND (c.nome ILIKE :q OR c.assunto ILIKE :q OR c.modelo_nome ILIKE :q
                OR EXISTS (SELECT 1 FROM comercial_campanha_destino d WHERE d.campanha_id = c.id AND d.email ILIKE :q))";
            $parametros['q'] = '%' . addcslashes($busca, '%_\\') . '%';
        }

        return [$onde, $parametros];
    }

    /**
     * @param array<string, mixed> $l
     *
     * @return LinhaDaCampanha
     */
    private static function linha(array $l): array
    {
        return [
            'id' => (int) $l['id'], 'nome' => (string) $l['nome'], 'assunto' => (string) $l['assunto'], 'titulo' => (string) $l['titulo'],
            'modelo_nome' => (string) $l['modelo_nome'], 'publico' => (string) $l['publico'], 'frequencia' => (string) $l['frequencia'],
            'estado' => (string) $l['estado'], 'total' => (int) $l['total'], 'execucoes' => (int) $l['execucoes'],
            'agendada_para' => $l['agendada_para'] === null ? null : (string) $l['agendada_para'], 'criada_em' => (string) $l['criada_em'],
        ];
    }
}
