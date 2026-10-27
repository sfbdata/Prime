<?php

declare(strict_types=1);

namespace App\Indicacao\Repository;

use App\Indicacao\Entity\Indicacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Indicações do BlueJus (spec `indicar-bluejus.md`). Dado de plataforma, sem tenant: toda consulta do lado do usuário
 * é pelo id de quem indica (o dono), e as do painel são do Master, que vê todas por desenho.
 *
 * "Virou conta" (comportamento 8) é calculado na leitura: indicação por e-mail cujo e-mail (já gravado em minúsculas)
 * tem conta criada DEPOIS da indicação, OU a atribuição de uma conta criada pelo link curto (`indicado_user_id`,
 * comportamento 7) ({@see self::VIROU_CONTA}). As consultas agregadas vão por SQL na conexão.
 *
 * A atribuição (`por_cadastro`) não é envio: fica fora das contagens de envio (limites, alerta, enviadas, janelas) por
 * {@see self::SO_ENVIOS}, mesmo depois que a conta nova for apagada (SET NULL), e entra só no "virou conta" (enquanto
 * a conta existir) e na lista da própria pessoa.
 *
 * @extends ServiceEntityRepository<Indicacao>
 */
class IndicacaoRepository extends ServiceEntityRepository
{
    /** Classe de trava (primeiro argumento de pg_advisory_xact_lock): as indicações de uma pessoa. */
    public const CLASSE_TRAVA = 4301;

    private const VIROU_CONTA = '(i.indicado_user_id IS NOT NULL OR EXISTS (SELECT 1 FROM "user" u WHERE lower(u.email) = i.email AND u.created_at > i.criada_em))';

    /**
     * A conta que a indicação virou (id do usuário), para contar cada conta uma vez só: a mesma pessoa indicada por
     * e-mail e atribuída pelo link, ou indicada por duas pessoas, é uma conta. Nulo quando não virou.
     */
    private const CONTA_CRIADA = 'COALESCE(i.indicado_user_id, (SELECT u.id FROM "user" u WHERE i.canal = \'email\' AND lower(u.email) = i.email AND u.created_at > i.criada_em ORDER BY u.id LIMIT 1))';

    /** Só os envios (os três canais); exclui a atribuição do cadastro pelo link, com ou sem a conta nova ainda existindo. */
    private const SO_ENVIOS = 'NOT i.por_cadastro';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Indicacao::class);
    }

    public function salvar(Indicacao $indicacao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($indicacao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Roda o trabalho numa transação do EntityManager (o `flush` sai no fim) com a trava das indicações da pessoa: dois
     * envios simultâneos dela contam um depois do outro, e o limite não estoura por corrida. A trava é advisory por
     * usuário (e não `FOR UPDATE` na linha de config), para uma pessoa não segurar as outras; cai no COMMIT.
     *
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacaoTravada(int $userId, \Closure $trabalho): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($userId, $trabalho): mixed {
            $this->conexao()->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CLASSE_TRAVA, $userId]);

            return $trabalho();
        });
    }

    public function contarDoUsuario(int $userId): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM indicacao WHERE indicado_por_id = ?', [$userId]);
    }

    /**
     * Janela móvel: conta os envios da pessoa (os três canais) feitos a partir de `$desde`. Alimenta os limites e o
     * alerta; a atribuição do cadastro pelo link não conta (não é envio).
     */
    public function contarDoUsuarioDesde(int $userId, \DateTimeImmutable $desde): int
    {
        return (int) $this->conexao()->fetchOne(
            'SELECT COUNT(*) FROM indicacao i WHERE i.indicado_por_id = ? AND i.criada_em > ? AND ' . self::SO_ENVIOS,
            [$userId, self::data($desde)],
        );
    }

    /**
     * As mais recentes da pessoa, com o "virou conta" de cada uma.
     *
     * @return list<array{nome: ?string, email: ?string, canal: string, criada_em: string, conta_criada: bool}>
     */
    public function ultimasDoUsuario(int $userId, int $limite): array
    {
        $linhas = $this->conexao()->fetchAllAssociative(
            'SELECT i.nome, i.email, i.canal, i.criada_em, ' . self::VIROU_CONTA . ' AS conta_criada
             FROM indicacao i WHERE i.indicado_por_id = ? ORDER BY i.criada_em DESC, i.id DESC LIMIT ' . max(1, $limite),
            [$userId],
        );

        return array_map(static fn (array $l): array => [
            'nome' => $l['nome'], 'email' => $l['email'], 'canal' => (string) $l['canal'],
            'criada_em' => (string) $l['criada_em'], 'conta_criada' => (bool) $l['conta_criada'],
        ], $linhas);
    }

    /** A conta nova já foi atribuída a alguém pelo link? (uma vez só por conta) */
    public function contaJaAtribuida(int $indicadoUserId): bool
    {
        return (bool) $this->conexao()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM indicacao WHERE indicado_user_id = ?)',
            [$indicadoUserId],
        );
    }

    /**
     * Grava a atribuição do cadastro pelo link fora do EntityManager, com `ON CONFLICT DO NOTHING` no índice único
     * parcial: uma corrida (o mesmo cadastro atribuído duas vezes) não lança nem fecha o EntityManager do request do
     * cadastro. Devolve se gravou (falso: a conta já estava atribuída).
     */
    public function gravarAtribuicao(Indicacao $atribuicao): bool
    {
        $indicado = $atribuicao->getIndicadoUser();
        if ($indicado === null) {
            throw new \InvalidArgumentException('Só a atribuição do cadastro pelo link passa por aqui.');
        }

        return $this->conexao()->executeStatement(
            'INSERT INTO indicacao (indicado_por_id, tenant_origem_id, canal, criada_em, indicado_user_id, por_cadastro)
             VALUES (?, ?, ?, ?, ?, true)
             ON CONFLICT (indicado_user_id) WHERE indicado_user_id IS NOT NULL DO NOTHING',
            [
                $atribuicao->getIndicadoPor()->getId(),
                $atribuicao->getTenantOrigem()?->getId(),
                $atribuicao->getCanal()->value,
                self::data($atribuicao->getCriadaEm()),
                $indicado->getId(),
            ],
        ) > 0;
    }

    /**
     * A atribuição do cadastro pelo link da conta nova e o escritório dela (o vínculo ativo mais antigo), para o
     * bônus de espaço. Nulo: a conta não chegou por indicação, ou ainda não tem escritório.
     *
     * @return array{indicacaoId: int, tenantId: int}|null
     */
    public function atribuicaoDoCadastro(int $indicadoUserId): ?array
    {
        $linha = $this->conexao()->fetchAssociative(
            'SELECT i.id AS indicacao_id, ut.tenant_id
             FROM indicacao i JOIN user_tenant ut ON ut.user_id = i.indicado_user_id AND ut.is_active = true
             WHERE i.indicado_user_id = ? AND i.por_cadastro
             ORDER BY ut.id LIMIT 1',
            [$indicadoUserId],
        );

        return $linha === false ? null : ['indicacaoId' => (int) $linha['indicacao_id'], 'tenantId' => (int) $linha['tenant_id']];
    }

    /**
     * O e-mail é de alguém com vínculo ativo NESTE escritório? Só o escritório de quem indica: consultar contas de fora
     * deixaria descobrir quem tem conta no BlueJus (spec, comportamento 4).
     */
    public function emailEhMembroDoEscritorio(string $email, int $tenantId): bool
    {
        return (bool) $this->conexao()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM user_tenant ut JOIN "user" u ON u.id = ut.user_id
             WHERE ut.tenant_id = ? AND ut.is_active = true AND lower(u.email) = ?)',
            [$tenantId, mb_strtolower(trim($email))],
        );
    }

    /**
     * Os números do card do Master. "Enviadas" e "últimas 24h" são só envios. "Viraram conta" conta contas distintas:
     * o mesmo e-mail indicado por duas pessoas, ou indicado e depois atribuído pelo link, é uma conta só.
     *
     * @return array{enviadas: int, ultimas24h: int, viraramConta: int}
     */
    public function totais(\DateTimeImmutable $desde24h): array
    {
        $linha = $this->conexao()->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE ' . self::SO_ENVIOS . ') AS enviadas,
                    COUNT(*) FILTER (WHERE ' . self::SO_ENVIOS . ' AND i.criada_em > :desde) AS ultimas24h,
                    COUNT(DISTINCT ' . self::CONTA_CRIADA . ') AS viraram
             FROM indicacao i',
            ['desde' => self::data($desde24h)],
        );

        return [
            'enviadas' => (int) ($linha['enviadas'] ?? 0),
            'ultimas24h' => (int) ($linha['ultimas24h'] ?? 0),
            'viraramConta' => (int) ($linha['viraram'] ?? 0),
        ];
    }

    /**
     * Uma linha por indicação, para o mapa do Master (modo Indicações): quem indicou, se foi envio e se virou conta.
     * Sem nome nem e-mail; o painel só conta, por UF e cidade de quem indicou.
     *
     * @return list<array{indicadorId: int, enviada: bool, virouConta: bool}>
     */
    public function paraOMapa(): array
    {
        $linhas = $this->conexao()->fetchAllAssociative(
            'SELECT i.indicado_por_id, (' . self::SO_ENVIOS . ') AS enviada, (' . self::VIROU_CONTA . ') AS virou_conta FROM indicacao i',
        );

        return array_map(static fn (array $l): array => [
            'indicadorId' => (int) $l['indicado_por_id'],
            'enviada' => (bool) $l['enviada'],
            'virouConta' => (bool) $l['virou_conta'],
        ], $linhas);
    }

    public function contarIndicadores(): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(DISTINCT indicado_por_id) FROM indicacao');
    }

    /**
     * Quem indicou, com as contagens, numa página. Ordem do card: mais envios nas últimas 24h, depois mais envios no
     * total (o id desempata, para a página ser estável). O escritório é o do envio mais recente. Total, janelas e
     * última são só envios; contas criadas conta cada conta uma vez (inclui as que vieram pelo link).
     *
     * @return list<array{user_id: int, nome: string, email: string, escritorio: ?string, total: int, ultimas24h: int,
     *     ultimos30d: int, contas_criadas: int, ultima_em: ?string, pausado: bool}>
     */
    public function indicadores(\DateTimeImmutable $desde24h, \DateTimeImmutable $desde30d, int $offset, int $limite): array
    {
        $linhas = $this->conexao()->fetchAllAssociative(
            'SELECT i.indicado_por_id AS user_id, u.full_name AS nome, u.email,
                    (SELECT t.name FROM indicacao r LEFT JOIN tenant t ON t.id = r.tenant_origem_id
                      WHERE r.indicado_por_id = i.indicado_por_id AND NOT r.por_cadastro
                      ORDER BY r.criada_em DESC, r.id DESC LIMIT 1) AS escritorio,
                    COUNT(*) FILTER (WHERE ' . self::SO_ENVIOS . ') AS total,
                    COUNT(*) FILTER (WHERE ' . self::SO_ENVIOS . ' AND i.criada_em > :d24) AS ultimas24h,
                    COUNT(*) FILTER (WHERE ' . self::SO_ENVIOS . ' AND i.criada_em > :d30) AS ultimos30d,
                    COUNT(DISTINCT ' . self::CONTA_CRIADA . ') AS contas_criadas,
                    MAX(i.criada_em) FILTER (WHERE ' . self::SO_ENVIOS . ') AS ultima_em,
                    EXISTS (SELECT 1 FROM indicacao_pausa p WHERE p.user_id = i.indicado_por_id) AS pausado
             FROM indicacao i JOIN "user" u ON u.id = i.indicado_por_id
             GROUP BY i.indicado_por_id, u.full_name, u.email
             ORDER BY ultimas24h DESC, total DESC, i.indicado_por_id ASC
             LIMIT :limite OFFSET :offset',
            ['d24' => self::data($desde24h), 'd30' => self::data($desde30d), 'limite' => max(1, $limite), 'offset' => max(0, $offset)],
        );

        return array_map(self::linhaDeIndicador(...), $linhas);
    }

    /**
     * Quem indicou, para a seção Recompensas do Master: uma linha por indicador (quem tem ao menos uma indicação, envio ou
     * atribuição pelo link), numa consulta só. O escritório é o do envio mais recente. Sem dado de indicado.
     *
     * @return list<array{user_id: int, nome: string, email: string, escritorio: ?string, envios: int}>
     */
    public function indicadoresParaRecompensas(): array
    {
        $linhas = $this->conexao()->fetchAllAssociative(
            'SELECT i.indicado_por_id AS user_id, u.full_name AS nome, u.email,
                    (SELECT t.name FROM indicacao r LEFT JOIN tenant t ON t.id = r.tenant_origem_id
                      WHERE r.indicado_por_id = i.indicado_por_id AND NOT r.por_cadastro
                      ORDER BY r.criada_em DESC, r.id DESC LIMIT 1) AS escritorio,
                    COUNT(*) FILTER (WHERE ' . self::SO_ENVIOS . ') AS envios
             FROM indicacao i JOIN "user" u ON u.id = i.indicado_por_id
             GROUP BY i.indicado_por_id, u.full_name, u.email',
        );

        return array_map(static fn (array $l): array => [
            'user_id' => (int) $l['user_id'], 'nome' => (string) $l['nome'], 'email' => (string) $l['email'],
            'escritorio' => $l['escritorio'] === null ? null : (string) $l['escritorio'], 'envios' => (int) $l['envios'],
        ], $linhas);
    }

    /**
     * As contas criadas por indicação, para as recompensas: UMA linha por conta (a mesma conta indicada por e-mail e pelo
     * link, ou por duas pessoas, vale uma vez só, para quem indicou primeiro). Traz quando a conta foi criada, se o
     * escritório dela tem ao menos uma pasta não excluída e os SINAIS do antifraude: escritórios e CNPJs da conta e do
     * indicador (todos os vínculos de cada um), o domínio do e-mail de cada um e se o Master já aprovou a conta. Nome e
     * e-mail de ninguém saem daqui; o domínio é só insumo da regra de suspeita.
     *
     * @return list<array{indicador_id: int, conta_id: int, conta_criada_em: string, tem_pasta: bool, aprovada: bool,
     *     escritorios_conta: list<int>, escritorios_indicador: list<int>, cnpjs_conta: list<string>, cnpjs_indicador: list<string>,
     *     dominio_conta: string, dominio_indicador: string}>
     */
    public function contasParaRecompensas(): array
    {
        // Só vínculo ativo com escritório não excluído gera sinal: vínculo morto e escritório apagado não provam ligação.
        $escritorios = 'SELECT string_agg(ut.tenant_id::text, \',\') FROM user_tenant ut JOIN tenant t ON t.id = ut.tenant_id WHERE ut.user_id = %s AND ut.is_active = true AND t.excluido_em IS NULL';
        $cnpjs = 'SELECT string_agg(DISTINCT t.cnpj, \',\') FROM user_tenant ut JOIN tenant t ON t.id = ut.tenant_id WHERE ut.user_id = %s AND ut.is_active = true AND t.excluido_em IS NULL AND t.cnpj IS NOT NULL';
        $linhas = $this->conexao()->fetchAllAssociative(
            'SELECT DISTINCT ON (conta.id) i.indicado_por_id AS indicador_id, conta.id AS conta_id, conta.created_at AS conta_criada_em,
                    EXISTS (SELECT 1 FROM user_tenant ut JOIN pasta p ON p.tenant_id = ut.tenant_id
                             WHERE ut.user_id = conta.id AND p.excluida_em IS NULL) AS tem_pasta,
                    EXISTS (SELECT 1 FROM aprovacao_de_conta_de_recompensa a
                             WHERE a.indicador_id = i.indicado_por_id AND a.conta_id = conta.id) AS aprovada,
                    (' . sprintf($escritorios, 'conta.id') . ') AS escritorios_conta,
                    (' . sprintf($escritorios, 'i.indicado_por_id') . ') AS escritorios_indicador,
                    (' . sprintf($cnpjs, 'conta.id') . ') AS cnpjs_conta,
                    (' . sprintf($cnpjs, 'i.indicado_por_id') . ') AS cnpjs_indicador,
                    split_part(lower(conta.email), \'@\', 2) AS dominio_conta,
                    (SELECT split_part(lower(ind.email), \'@\', 2) FROM "user" ind WHERE ind.id = i.indicado_por_id) AS dominio_indicador
             FROM indicacao i JOIN "user" conta ON conta.id = ' . self::CONTA_CRIADA . '
             ORDER BY conta.id, i.criada_em, i.id',
        );

        return array_map(static fn (array $l): array => [
            'indicador_id' => (int) $l['indicador_id'], 'conta_id' => (int) $l['conta_id'], 'conta_criada_em' => (string) $l['conta_criada_em'],
            'tem_pasta' => (bool) $l['tem_pasta'], 'aprovada' => (bool) $l['aprovada'],
            'escritorios_conta' => array_map('intval', self::lista($l['escritorios_conta'])),
            'escritorios_indicador' => array_map('intval', self::lista($l['escritorios_indicador'])),
            'cnpjs_conta' => self::lista($l['cnpjs_conta']), 'cnpjs_indicador' => self::lista($l['cnpjs_indicador']),
            'dominio_conta' => (string) $l['dominio_conta'], 'dominio_indicador' => (string) $l['dominio_indicador'],
        ], $linhas);
    }

    /** @return list<string> */
    private static function lista(mixed $agregado): array
    {
        return $agregado === null || $agregado === '' ? [] : explode(',', (string) $agregado);
    }

    /**
     * @param array<string, mixed> $l
     *
     * @return array{user_id: int, nome: string, email: string, escritorio: ?string, total: int, ultimas24h: int,
     *     ultimos30d: int, contas_criadas: int, ultima_em: ?string, pausado: bool}
     */
    private static function linhaDeIndicador(array $l): array
    {
        return [
            'user_id' => (int) $l['user_id'], 'nome' => (string) $l['nome'], 'email' => (string) $l['email'],
            'escritorio' => $l['escritorio'] === null ? null : (string) $l['escritorio'],
            'total' => (int) $l['total'], 'ultimas24h' => (int) $l['ultimas24h'], 'ultimos30d' => (int) $l['ultimos30d'],
            'contas_criadas' => (int) $l['contas_criadas'],
            'ultima_em' => $l['ultima_em'] === null ? null : (string) $l['ultima_em'], 'pausado' => (bool) $l['pausado'],
        ];
    }

    private function conexao(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    private static function data(\DateTimeImmutable $data): string
    {
        return $data->format('Y-m-d H:i:s');
    }
}
