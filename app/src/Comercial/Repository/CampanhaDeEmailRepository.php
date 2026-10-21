<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\CampanhaDeEmail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As campanhas de e-mail (spec `comercial-e-emails.md` §8.4 e §8.5), por SQL na conexão DBAL, como o resto do
 * Comercial: a confirmação é um INSERT idempotente (`ON CONFLICT` na chave de idempotência), o expansor trava a
 * campanha devida com `FOR UPDATE` e as mudanças de estado são UPDATE condicionais (só mudam quem ainda está no estado
 * esperado). Plataforma, do Master: nenhuma consulta filtra por escritório (a tabela não tem `tenant_id`).
 *
 * A trilha manual (R6) vai ao `audit_log` na mesma transação: `camp_conf` na confirmação e `camp_fim` no encerramento,
 * com `changes` só `{publico, frequencia, total}` (sem o assunto, por minimização), e `camp_canc` em cada cancelamento
 * automático (a chave desligada, o atraso de mais de 24 h), com `{motivo, total}`.
 *
 * @phpstan-type Campanha array{
 *     id: int, chave_idempotencia: string, nome: string, modelo_chave: string, modelo_nome: string, modo: string,
 *     assunto: string, selo: string, titulo: string, conteudo: string, publico: string,
 *     publico_parametros: list<array{email: string, nome: string}>, publico_filtro: array<string, string>,
 *     frequencia: string, agendada_para: ?string,
 *     proxima_execucao: ?string, estado: string, motivo: ?string, execucoes: int, total: int, criada_em: string
 * }
 *
 * @extends ServiceEntityRepository<CampanhaDeEmail>
 */
class CampanhaDeEmailRepository extends ServiceEntityRepository
{
    public const CONFIRMADA = 'camp_conf';
    public const ENCERRADA = 'camp_fim';
    public const CANCELADA = 'camp_canc';

    private const COLUNAS = 'id, chave_idempotencia, nome, modelo_chave, modelo_nome, modo, assunto, selo, titulo, conteudo,
        publico, publico_parametros, frequencia, agendada_para, proxima_execucao, estado, motivo, execucoes, total, criada_em';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampanhaDeEmail::class);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacao(\Closure $trabalho): mixed
    {
        return $this->conexao()->transactional(static fn (): mixed => $trabalho());
    }

    /** @return Campanha|null */
    public function porChave(string $chave): ?array
    {
        return $this->uma('WHERE chave_idempotencia = :chave', ['chave' => $chave]);
    }

    /** @return Campanha|null */
    public function porId(int $id): ?array
    {
        return $this->uma('WHERE id = :id', ['id' => $id]);
    }

    /**
     * Grava a campanha confirmada. Null se a chave de idempotência já existia (clique duplo: nada novo é gravado).
     *
     * @param array{chave: string, assunto: string, modelo_chave: string, modelo_nome: string, modo: string, selo: string,
     *     titulo: string, conteudo: string, publico: string, parametros: list<array{email: string, nome: string}>|array{informados: ?list<array{email: string, nome: string}>, filtro: array<string, string>}|null,
     *     frequencia: string, agendada_para: ?\DateTimeImmutable, proxima: \DateTimeImmutable, estado: string,
     *     total: int, criada_por: ?int, agora: \DateTimeImmutable} $c
     */
    public function inserir(array $c): ?int
    {
        $id = $this->conexao()->fetchOne(
            'INSERT INTO comercial_campanha (chave_idempotencia, nome, modelo_chave, modelo_nome, modo, assunto, selo, titulo,
                 conteudo, publico, publico_parametros, frequencia, agendada_para, proxima_execucao, estado, execucoes, total,
                 criada_por_id, criada_em)
             VALUES (:chave, :nome, :mchave, :mnome, :modo, :assunto, :selo, :titulo, :conteudo, :publico, :parametros,
                 :frequencia, :agendada, :proxima, :estado, 0, :total, :por, :agora)
             ON CONFLICT (chave_idempotencia) DO NOTHING RETURNING id',
            [
                'chave' => $c['chave'], 'nome' => mb_substr($c['assunto'], 0, 255), 'mchave' => mb_substr($c['modelo_chave'], 0, 40),
                'mnome' => mb_substr($c['modelo_nome'], 0, 200), 'modo' => $c['modo'], 'assunto' => mb_substr($c['assunto'], 0, 255),
                'selo' => mb_substr($c['selo'], 0, 120), 'titulo' => mb_substr($c['titulo'], 0, 255), 'conteudo' => $c['conteudo'],
                'publico' => $c['publico'], 'parametros' => $c['parametros'] === null ? null : json_encode($c['parametros'], JSON_THROW_ON_ERROR),
                'frequencia' => $c['frequencia'], 'agendada' => self::dataOuNulo($c['agendada_para']), 'proxima' => self::data($c['proxima']),
                'estado' => $c['estado'], 'total' => $c['total'], 'por' => $c['criada_por'], 'agora' => self::data($c['agora']),
            ],
        );

        return $id === false ? null : (int) $id;
    }

    /**
     * A linha manual da trilha (R6): sem o assunto nem os endereços.
     *
     * @param array<string, string|int> $changes `{publico, frequencia, total}` ou, no cancelamento, `{motivo, total}`
     */
    public function auditar(int $id, string $acao, array $changes, ?int $porId, ?string $porEmail, \DateTimeImmutable $agora): void
    {
        $this->conexao()->executeStatement(
            'INSERT INTO audit_log (action, entity_class, entity_id, changes, actor_user_id, actor_email, created_at)
             VALUES (:acao, :classe, :id, :changes, :por, :email, :agora)',
            [
                'acao' => $acao, 'classe' => CampanhaDeEmail::class, 'id' => (string) $id,
                'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'por' => $porId, 'email' => $porEmail, 'agora' => self::data($agora),
            ],
        );
    }

    public function haAtivas(): bool
    {
        return $this->conexao()->fetchOne(
            'SELECT 1 FROM comercial_campanha WHERE estado IN (:ativas) LIMIT 1',
            ['ativas' => CampanhaDeEmail::ATIVAS],
            ['ativas' => ArrayParameterType::STRING],
        ) !== false;
    }

    /**
     * A parada de emergência (§8.1.2): toda campanha ativa vira `cancelada` com o motivo.
     *
     * @return list<array{id: int, total: int}> as canceladas
     */
    public function cancelarAtivas(string $motivo): array
    {
        $linhas = $this->conexao()->fetchAllAssociative(
            "UPDATE comercial_campanha SET estado = 'cancelada', motivo = :motivo, publico_parametros = NULL, proxima_execucao = NULL
             WHERE estado IN (:ativas) RETURNING id, total",
            ['motivo' => $motivo, 'ativas' => CampanhaDeEmail::ATIVAS],
            ['ativas' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'total' => (int) $l['total']], $linhas);
    }

    /**
     * Encerra a campanha se ainda ativa (o freio do console, §8.1.5). Null se não existe ou já não está ativa.
     *
     * @return array{publico: string, frequencia: string, total: int}|null
     */
    public function encerrar(int $id, string $motivo): ?array
    {
        $linha = $this->conexao()->fetchAssociative(
            "UPDATE comercial_campanha SET estado = 'cancelada', motivo = :motivo, publico_parametros = NULL, proxima_execucao = NULL
             WHERE id = :id AND estado IN (:ativas) RETURNING publico, frequencia, total",
            ['id' => $id, 'motivo' => $motivo, 'ativas' => CampanhaDeEmail::ATIVAS],
            ['ativas' => ArrayParameterType::STRING],
        );

        return $linha === false ? null : ['publico' => (string) $linha['publico'], 'frequencia' => (string) $linha['frequencia'], 'total' => (int) $linha['total']];
    }

    /**
     * Cancela uma campanha que ainda está no estado esperado (a agendada que acordou atrasada demais). Devolve o total
     * dela, ou null se ela já não estava naquele estado.
     */
    public function cancelar(int $id, string $estadoEsperado, string $motivo): ?int
    {
        $total = $this->conexao()->fetchOne(
            "UPDATE comercial_campanha SET estado = 'cancelada', motivo = :motivo, publico_parametros = NULL, proxima_execucao = NULL
             WHERE id = :id AND estado = :esperado RETURNING total",
            ['id' => $id, 'esperado' => $estadoEsperado, 'motivo' => $motivo],
        );

        return $total === false ? null : (int) $total;
    }

    /**
     * O freio do console numa campanha já `enviada` que ainda tem envios na fila do Carteiro (§8.1.5): ela vira
     * `cancelada`, com o motivo. Null se não está `enviada` ou não tem nada na fila.
     *
     * @return array{publico: string, frequencia: string, total: int}|null
     */
    public function encerrarEnviadaComFila(int $id, string $motivo): ?array
    {
        $linha = $this->conexao()->fetchAssociative(
            "UPDATE comercial_campanha SET estado = 'cancelada', motivo = :motivo
             WHERE id = :id AND estado = 'enviada' AND EXISTS (
                 SELECT 1 FROM comercial_envio e WHERE e.origem = :origem AND e.situacao IN ('enfileirado', 'enviando'))
             RETURNING publico, frequencia, total",
            ['id' => $id, 'motivo' => $motivo, 'origem' => 'campanha:' . $id],
        );

        return $linha === false ? null : ['publico' => (string) $linha['publico'], 'frequencia' => (string) $linha['frequencia'], 'total' => (int) $linha['total']];
    }

    /**
     * As campanhas com execução devida (sem a recorrente que ainda tem lote da execução anterior por sair).
     *
     * @return list<Campanha>
     */
    public function devidas(\DateTimeImmutable $agora): array
    {
        return $this->varias(
            "WHERE estado IN ('na_fila', 'agendada', 'recorrente') AND proxima_execucao <= :agora
               AND NOT EXISTS (SELECT 1 FROM comercial_campanha_destino d
                               WHERE d.campanha_id = comercial_campanha.id AND d.execucao = comercial_campanha.execucoes AND d.envio_id IS NULL)
             ORDER BY proxima_execucao, id",
            ['agora' => self::data($agora)],
        );
    }

    /**
     * Trava a campanha para materializar a execução, se ela continua como foi lida (estado e próxima execução): dois
     * passos do expansor ao mesmo tempo não materializam a mesma execução.
     *
     * @return Campanha|null
     */
    public function travarDevida(int $id, string $estado, int $execucoes): ?array
    {
        return $this->uma('WHERE id = :id AND estado = :estado AND execucoes = :execucoes FOR UPDATE', ['id' => $id, 'estado' => $estado, 'execucoes' => $execucoes]);
    }

    /** Registra a execução materializada: o número, o total, o estado seguinte e a próxima data (null na única). */
    public function registrarExecucao(int $id, int $execucao, int $total, string $estado, ?\DateTimeImmutable $proxima): void
    {
        $this->conexao()->executeStatement(
            "UPDATE comercial_campanha SET execucoes = :execucao, total = :total, estado = :estado, proxima_execucao = :proxima,
                 publico_parametros = CASE WHEN :estado IN ('enviada', 'cancelada') THEN NULL ELSE publico_parametros END
             WHERE id = :id",
            ['id' => $id, 'execucao' => $execucao, 'total' => $total, 'estado' => $estado, 'proxima' => self::dataOuNulo($proxima)],
        );
    }

    /** A recorrente que perdeu a vez pula para a data dada, sem enviar (§8.1.4). */
    public function adiar(int $id, \DateTimeImmutable $proxima): void
    {
        $this->conexao()->executeStatement(
            "UPDATE comercial_campanha SET proxima_execucao = :proxima WHERE id = :id AND estado = 'recorrente'",
            ['id' => $id, 'proxima' => self::data($proxima)],
        );
    }

    /** A única com o último destinatário entregue ao Carteiro vira `enviada`, e os parâmetros do público somem. */
    public function concluirEnviadas(): int
    {
        return (int) $this->conexao()->executeStatement(
            "UPDATE comercial_campanha SET estado = 'enviada', publico_parametros = NULL
             WHERE estado = 'enviando' AND NOT EXISTS (
                 SELECT 1 FROM comercial_campanha_destino d
                 WHERE d.campanha_id = comercial_campanha.id AND d.execucao = comercial_campanha.execucoes AND d.envio_id IS NULL)",
        );
    }

    public function estaCancelada(int $id): bool
    {
        return $this->conexao()->fetchOne('SELECT estado FROM comercial_campanha WHERE id = :id', ['id' => $id]) === CampanhaDeEmail::CANCELADA;
    }

    /**
     * As últimas campanhas, da mais nova à mais antiga (a lista do dc L.586).
     *
     * @return list<Campanha>
     */
    public function ultimas(int $limite): array
    {
        return $this->varias('ORDER BY id DESC LIMIT ' . max(1, $limite), []);
    }

    /**
     * @param array<string, mixed> $parametros
     *
     * @return Campanha|null
     */
    private function uma(string $resto, array $parametros): ?array
    {
        $linha = $this->conexao()->fetchAssociative('SELECT ' . self::COLUNAS . ' FROM comercial_campanha ' . $resto, $parametros);

        return $linha === false ? null : self::campanha($linha);
    }

    /**
     * @param array<string, mixed> $parametros
     *
     * @return list<Campanha>
     */
    private function varias(string $resto, array $parametros): array
    {
        $linhas = $this->conexao()->fetchAllAssociative('SELECT ' . self::COLUNAS . ' FROM comercial_campanha ' . $resto, $parametros);

        return array_map(self::campanha(...), $linhas);
    }

    /**
     * @param array<string, mixed> $l
     *
     * @return Campanha
     */
    private static function campanha(array $l): array
    {
        $parametros = \is_string($l['publico_parametros']) ? json_decode($l['publico_parametros'], true, 8, JSON_THROW_ON_ERROR) : [];
        $parametros = \is_array($parametros) ? $parametros : [];
        // Com filtro (§9) a campanha guarda {informados, filtro}; sem filtro, só a lista (o formato de antes).
        $comFiltro = !array_is_list($parametros);

        return [
            'id' => (int) $l['id'], 'chave_idempotencia' => (string) $l['chave_idempotencia'], 'nome' => (string) $l['nome'],
            'modelo_chave' => (string) $l['modelo_chave'], 'modelo_nome' => (string) $l['modelo_nome'], 'modo' => (string) $l['modo'],
            'assunto' => (string) $l['assunto'], 'selo' => (string) $l['selo'], 'titulo' => (string) $l['titulo'],
            'conteudo' => (string) $l['conteudo'], 'publico' => (string) $l['publico'],
            'publico_parametros' => self::destinatarios($comFiltro ? (array) ($parametros['informados'] ?? []) : $parametros),
            'publico_filtro' => $comFiltro ? self::filtro($parametros['filtro'] ?? []) : [],
            'frequencia' => (string) $l['frequencia'], 'agendada_para' => self::textoOuNulo($l['agendada_para']),
            'proxima_execucao' => self::textoOuNulo($l['proxima_execucao']), 'estado' => (string) $l['estado'],
            'motivo' => self::textoOuNulo($l['motivo']), 'execucoes' => (int) $l['execucoes'], 'total' => (int) $l['total'],
            'criada_em' => (string) $l['criada_em'],
        ];
    }

    /**
     * @param array<mixed> $lista
     *
     * @return list<array{email: string, nome: string}>
     */
    private static function destinatarios(array $lista): array
    {
        $saida = [];
        foreach ($lista as $item) {
            if (\is_array($item) && \is_string($item['email'] ?? null)) {
                $saida[] = ['email' => $item['email'], 'nome' => \is_string($item['nome'] ?? null) ? $item['nome'] : ''];
            }
        }

        return $saida;
    }

    /** @return array<string, string> */
    private static function filtro(mixed $filtro): array
    {
        return \is_array($filtro) ? array_filter(array_map(static fn ($v): string => \is_string($v) ? $v : '', $filtro), static fn (string $v): bool => $v !== '') : [];
    }

    private static function textoOuNulo(mixed $valor): ?string
    {
        return $valor === null ? null : (string) $valor;
    }

    private function conexao(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    private static function data(\DateTimeImmutable $em): string
    {
        return $em->format('Y-m-d H:i:s');
    }

    private static function dataOuNulo(?\DateTimeImmutable $em): ?string
    {
        return $em === null ? null : self::data($em);
    }
}
