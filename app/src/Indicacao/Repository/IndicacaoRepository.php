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
 * tem conta criada DEPOIS da indicação ({@see self::VIROU_CONTA}). As consultas agregadas vão por SQL na conexão.
 *
 * @extends ServiceEntityRepository<Indicacao>
 */
class IndicacaoRepository extends ServiceEntityRepository
{
    /** Classe de trava (primeiro argumento de pg_advisory_xact_lock): as indicações de uma pessoa. */
    public const CLASSE_TRAVA = 4301;

    private const VIROU_CONTA = 'EXISTS (SELECT 1 FROM "user" u WHERE lower(u.email) = i.email AND u.created_at > i.criada_em)';

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

    /** Janela móvel: conta as indicações da pessoa (os três canais) criadas a partir de `$desde`. */
    public function contarDoUsuarioDesde(int $userId, \DateTimeImmutable $desde): int
    {
        return (int) $this->conexao()->fetchOne(
            'SELECT COUNT(*) FROM indicacao WHERE indicado_por_id = ? AND criada_em > ?',
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
     * Os números do card do Master. "Viraram conta" conta endereços distintos: o mesmo e-mail indicado por duas
     * pessoas é uma conta só.
     *
     * @return array{enviadas: int, ultimas24h: int, viraramConta: int}
     */
    public function totais(\DateTimeImmutable $desde24h): array
    {
        $linha = $this->conexao()->fetchAssociative(
            'SELECT COUNT(*) AS enviadas, COUNT(*) FILTER (WHERE i.criada_em > :desde) AS ultimas24h,
                    COUNT(DISTINCT i.email) FILTER (WHERE i.canal = \'email\' AND ' . self::VIROU_CONTA . ') AS viraram
             FROM indicacao i',
            ['desde' => self::data($desde24h)],
        );

        return [
            'enviadas' => (int) ($linha['enviadas'] ?? 0),
            'ultimas24h' => (int) ($linha['ultimas24h'] ?? 0),
            'viraramConta' => (int) ($linha['viraram'] ?? 0),
        ];
    }

    public function contarIndicadores(): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(DISTINCT indicado_por_id) FROM indicacao');
    }

    /**
     * Quem indicou, com as contagens, numa página. Ordem do card: mais envios nas últimas 24h, depois mais envios no
     * total (o id desempata, para a página ser estável). O escritório é o da indicação mais recente.
     *
     * @return list<array{user_id: int, nome: string, email: string, escritorio: ?string, total: int, ultimas24h: int,
     *     ultimos30d: int, contas_criadas: int, ultima_em: ?string, pausado: bool}>
     */
    public function indicadores(\DateTimeImmutable $desde24h, \DateTimeImmutable $desde30d, int $offset, int $limite): array
    {
        $linhas = $this->conexao()->fetchAllAssociative(
            'SELECT i.indicado_por_id AS user_id, u.full_name AS nome, u.email,
                    (SELECT t.name FROM indicacao r LEFT JOIN tenant t ON t.id = r.tenant_origem_id
                      WHERE r.indicado_por_id = i.indicado_por_id ORDER BY r.criada_em DESC, r.id DESC LIMIT 1) AS escritorio,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE i.criada_em > :d24) AS ultimas24h,
                    COUNT(*) FILTER (WHERE i.criada_em > :d30) AS ultimos30d,
                    COUNT(DISTINCT i.email) FILTER (WHERE i.canal = \'email\' AND ' . self::VIROU_CONTA . ') AS contas_criadas,
                    MAX(i.criada_em) AS ultima_em,
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
