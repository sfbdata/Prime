<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Campanha\PublicosDaCampanha;
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
 * As marcas são de cada Master (`comercial_campanha_marca`, F3e; spec `comercial-marcas.md`): `est` (Com estrela) é
 * estrela e não arquivada, `arq` (Arquivadas) é a arquivada, que sai das outras quatro; a removida não aparece em aba
 * nenhuma nem conta. Por isso a lista recebe o id do Master logado.
 *
 * @phpstan-type LinhaDaCampanha array{
 *     id: int, nome: string, assunto: string, titulo: string, modelo_nome: string, publico: string, frequencia: string,
 *     estado: string, total: int, execucoes: int, agendada_para: ?string, criada_em: string, rastrear_leitura: bool, estrela: bool
 * }
 * @phpstan-type Metricas array{destinatarios: int, na_fila: int, enviados: int, entregues: int, lidos: int, rejeitados: int, clicaram: int}
 * @phpstan-type Destinatario array{email: string, situacao: ?string, aberto: bool, clicou: bool}
 * @phpstan-type Contagens array{env: int, ag: int, rec: int, est: int, arq: int}
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

    public const ABAS = ['env', 'ag', 'rec', 'est', 'arq'];

    private const GRUPO = "CASE c.estado WHEN 'recorrente' THEN 'rec' WHEN 'agendada' THEN 'ag' ELSE 'env' END";
    /** As marcas do Master logado (sem linha, sem marca) e a regra de cada uma. */
    private const MARCAS = 'comercial_campanha c LEFT JOIN comercial_campanha_marca m ON m.campanha_id = c.id AND m.user_id = :master';
    private const ARQUIVADA = 'COALESCE(m.arquivada, false)';
    private const ESTRELA = 'COALESCE(m.estrela, false)';
    private const VISIVEL = 'm.removida_em IS NULL';

    public function __construct(private readonly Connection $conexao)
    {
    }

    /** @return Contagens quantas campanhas em cada aba, para o Master (sem a busca, como o dc L700) */
    public function contarPorAba(int $masterId): array
    {
        $aberta = 'NOT ' . self::ARQUIVADA . ' AND ' . self::GRUPO;
        $l = (array) $this->conexao->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE ' . $aberta . " = 'env') AS env, COUNT(*) FILTER (WHERE " . $aberta . " = 'ag') AS ag,
                    COUNT(*) FILTER (WHERE " . $aberta . " = 'rec') AS rec,
                    COUNT(*) FILTER (WHERE " . self::ESTRELA . ' AND NOT ' . self::ARQUIVADA . ') AS est,
                    COUNT(*) FILTER (WHERE ' . self::ARQUIVADA . ') AS arq
             FROM ' . self::MARCAS . ' WHERE ' . self::VISIVEL,
            ['master' => $masterId],
        );

        return ['env' => (int) ($l['env'] ?? 0), 'ag' => (int) ($l['ag'] ?? 0), 'rec' => (int) ($l['rec'] ?? 0), 'est' => (int) ($l['est'] ?? 0), 'arq' => (int) ($l['arq'] ?? 0)];
    }

    /** Quantas campanhas a aba tem com a busca (por nome, assunto, modelo ou e-mail de um destinatário), para o Master. */
    public function contar(int $masterId, string $aba, string $busca): int
    {
        [$onde, $parametros] = self::filtro($masterId, $aba, $busca);

        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM ' . self::MARCAS . ' WHERE ' . $onde, $parametros);
    }

    /**
     * Uma página da aba, da mais nova à mais antiga.
     *
     * @return list<LinhaDaCampanha>
     */
    public function pagina(int $masterId, string $aba, string $busca, int $limite, int $deslocamento): array
    {
        [$onde, $parametros] = self::filtro($masterId, $aba, $busca);
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT c.id, c.nome, c.assunto, c.titulo, c.modelo_nome, c.publico, c.frequencia, c.estado, c.total, c.execucoes,
                    c.agendada_para, c.criada_em, c.rastrear_leitura, ' . self::ESTRELA . ' AS estrela
             FROM ' . self::MARCAS . ' WHERE ' . $onde . ' ORDER BY c.id DESC LIMIT ' . max(1, $limite) . ' OFFSET ' . max(0, $deslocamento),
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

    /**
     * Todos os destinatários da campanha para o "Exportar destinatários" (spec `comercial-marcas.md`, regra 6), da
     * execução mais nova para a mais antiga, sem o e-mail anonimizado pela retenção (o hash não tem `@`).
     *
     * @return list<array{email: string, situacao: ?string, aberto: bool}>
     */
    public function destinatariosParaExportar(int $campanhaId): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            "SELECT d.email, e.situacao, e.primeira_abertura_em IS NOT NULL AS aberto FROM comercial_campanha_destino d LEFT JOIN comercial_envio e ON e.id = d.envio_id
             WHERE d.campanha_id = :id AND d.email LIKE '%@%' ORDER BY d.execucao DESC, d.id",
            ['id' => $campanhaId],
        );

        return array_map(static fn (array $l): array => [
            'email' => (string) $l['email'], 'situacao' => $l['situacao'] === null ? null : (string) $l['situacao'],
            'aberto' => (bool) $l['aberto'],
        ], $linhas);
    }

    /**
     * @return array{0: string, 1: array<string, string|int>}
     */
    private static function filtro(int $masterId, string $aba, string $busca): array
    {
        $parametros = ['master' => $masterId];
        $onde = self::VISIVEL . ' AND ' . self::condicaoDaAba($aba, $parametros);
        if ($busca !== '') {
            $onde .= " AND (c.nome ILIKE :q OR c.assunto ILIKE :q OR c.modelo_nome ILIKE :q" . self::buscaNoPublico($busca, $parametros) . "
                OR EXISTS (SELECT 1 FROM comercial_campanha_destino d WHERE d.campanha_id = c.id AND d.email ILIKE :q))";
            $parametros['q'] = '%' . addcslashes($busca, '%_\\') . '%';
        }

        return [$onde, $parametros];
    }

    /**
     * A regra da aba (dc L698): a arquivada só em Arquivadas; Com estrela é estrela e não arquivada; as três do estado
     * deixam a arquivada de fora.
     *
     * @param array<string, string|int> $parametros
     */
    private static function condicaoDaAba(string $aba, array &$parametros): string
    {
        if ($aba === 'arq') {
            return self::ARQUIVADA;
        }
        if ($aba === 'est') {
            return self::ESTRELA . ' AND NOT ' . self::ARQUIVADA;
        }
        $parametros['aba'] = $aba;

        return self::GRUPO . ' = :aba AND NOT ' . self::ARQUIVADA;
    }

    /**
     * O público é gravado pela chave ('prim') e a tela mostra o rótulo ("Usuários primários"), como o dc L699 busca:
     * as chaves cujo rótulo contém a busca entram como parâmetros.
     *
     * @param array<string, string|int> $parametros
     */
    private static function buscaNoPublico(string $busca, array &$parametros): string
    {
        $chaves = array_keys(array_filter(PublicosDaCampanha::ROTULOS, static fn (string $r): bool => mb_stripos($r, $busca) !== false));
        if ($chaves === []) {
            return '';
        }
        $marcas = [];
        foreach ($chaves as $k => $chave) {
            $parametros['pub' . $k] = $chave;
            $marcas[] = ':pub' . $k;
        }

        return ' OR c.publico IN (' . implode(', ', $marcas) . ')';
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
            'rastrear_leitura' => (bool) $l['rastrear_leitura'],
            'estrela' => (bool) ($l['estrela'] ?? false),
        ];
    }
}
