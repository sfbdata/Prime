<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\ContatoComercial;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A Base de contatos do Comercial (`comercial_contato`). Plataforma, do Master: a tabela não tem `tenant_id` e
 * nenhuma consulta filtra por escritório (as rotas são `ROLE_SUPER_ADMIN`). A busca é no servidor, sem acento e sem
 * caixa (`unaccent` + `lower`); o `%` e o `_` digitados valem como letra.
 *
 * @extends ServiceEntityRepository<ContatoComercial>
 */
class ContatoComercialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContatoComercial::class);
    }

    /**
     * Grava o contato novo por SQL com `ON CONFLICT (email) DO NOTHING`: duas requisições ao mesmo tempo com o mesmo
     * endereço não dão erro de chave única (que fecharia o EntityManager) nem duplicam; quem perde a corrida recebe null.
     *
     * @return int|null o id do contato criado, ou null se o endereço já estava na base
     */
    public function inserirSeNovo(ContatoComercial $contato): ?int
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO comercial_contato (email, nome, origem, origem_detalhe, importado_por_id, criado_em, atualizado_em)
             VALUES (:email, :nome, :origem, :detalhe, :por, :criado, :atualizado)
             ON CONFLICT (email) DO NOTHING RETURNING id',
            [
                'email' => $contato->getEmail(),
                'nome' => $contato->getNome(),
                'origem' => $contato->getOrigem(),
                'detalhe' => $contato->getOrigemDetalhe(),
                'por' => $contato->getImportadoPorId(),
                'criado' => $contato->getCriadoEm()->format('Y-m-d H:i:s'),
                'atualizado' => $contato->getAtualizadoEm()->format('Y-m-d H:i:s'),
            ],
        );

        return $id === false ? null : (int) $id;
    }

    public function porEmail(string $email): ?ContatoComercial
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Os contatos que já existem, pelo e-mail normalizado.
     *
     * @param list<string> $emails
     *
     * @return array<string, ContatoComercial> indexados pelo e-mail
     */
    public function porEmails(array $emails): array
    {
        $achados = [];
        foreach (array_chunk(array_values(array_unique($emails)), 500) as $lote) {
            foreach ($this->findBy(['email' => $lote]) as $contato) {
                $achados[$contato->getEmail()] = $contato;
            }
        }

        return $achados;
    }

    /**
     * Uma página da lista, do mais recente para o mais antigo (o id desempata).
     *
     * @return array{total: int, itens: list<array<string, mixed>>}
     */
    public function pagina(?string $busca, int $pagina, int $porPagina): array
    {
        [$filtro, $parametros] = self::filtro($busca);
        $conexao = $this->getEntityManager()->getConnection();
        $total = (int) $conexao->fetchOne('SELECT COUNT(*) FROM comercial_contato c WHERE ' . $filtro, $parametros);
        /** @var list<array<string, mixed>> $itens */
        $itens = $conexao->fetchAllAssociative(
            'SELECT c.id, c.email, c.nome, c.origem, c.origem_detalhe, c.criado_em, c.ultimo_convite_em FROM comercial_contato c
             WHERE ' . $filtro . ' ORDER BY c.criado_em DESC, c.id DESC LIMIT ' . max(1, $porPagina) . ' OFFSET ' . max(0, ($pagina - 1) * $porPagina),
            $parametros,
        );

        return ['total' => $total, 'itens' => $itens];
    }

    /**
     * Todos os contatos com o dia do último convite, para o público `contatos` das campanhas (ordem de entrada na base).
     *
     * @return list<array{email: string, nome: string, ultimoConviteEm: ?\DateTimeImmutable}>
     */
    public function paraConvite(): array
    {
        /** @var list<array{email: string, nome: ?string, ultimo_convite_em: ?string}> $linhas */
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT c.email, c.nome, c.ultimo_convite_em FROM comercial_contato c ORDER BY c.id',
        );

        return array_map(static fn (array $l): array => [
            'email' => $l['email'],
            'nome' => (string) $l['nome'],
            'ultimoConviteEm' => $l['ultimo_convite_em'] === null ? null : new \DateTimeImmutable($l['ultimo_convite_em']),
        ], $linhas);
    }

    /**
     * A origem (`importacao` ou `manual`) de cada endereço que está na base, para o rodapé do convite dizer de onde o
     * contato veio (C1). Quem não está na base não aparece. Uma consulta por chamada, em fatias de 1.000.
     *
     * @param list<string> $emails
     *
     * @return array<string, string> e-mail (minúsculo) => origem
     */
    public function origensPorEmail(array $emails): array
    {
        $origens = [];
        foreach (array_chunk(array_values(array_unique(array_map(static fn (string $e): string => mb_strtolower(trim($e)), $emails))), 1000) as $parte) {
            /** @var list<array{email: string, origem: string}> $linhas */
            $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
                'SELECT c.email, c.origem FROM comercial_contato c WHERE c.email IN (:emails)',
                ['emails' => $parte],
                ['emails' => ArrayParameterType::STRING],
            );
            foreach ($linhas as $l) {
                $origens[$l['email']] = $l['origem'];
            }
        }

        return $origens;
    }

    /** Classe da trava advisory dos convites (primeiro argumento de `pg_advisory_xact_lock`; o segundo é sempre 0). */
    public const TRAVA_DOS_CONVITES = 4741;

    /** Trava de transação: solta no COMMIT ou no ROLLBACK. Chamar só dentro de uma transação (ver PublicosDaCampanha::travarConvites). */
    public function travarConvites(): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::TRAVA_DOS_CONVITES, 0]);
    }

    /** Quantos contatos há na base (o número do público na grade). */
    public function contar(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM comercial_contato');
    }

    /**
     * Grava o convite de agora nos contatos, em lotes (um UPDATE por 1.000 endereços, sem uma consulta por contato).
     *
     * @param list<string> $emails
     *
     * @return int quantos contatos foram marcados
     */
    public function marcarConvite(array $emails, \DateTimeImmutable $agora): int
    {
        $marcados = 0;
        foreach (array_chunk(array_values(array_unique($emails)), 1000) as $lote) {
            $marcados += (int) $this->getEntityManager()->getConnection()->executeStatement(
                'UPDATE comercial_contato SET ultimo_convite_em = :agora WHERE email IN (:emails)',
                ['agora' => $agora->format('Y-m-d H:i:s'), 'emails' => $lote],
                ['emails' => ArrayParameterType::STRING],
            );
        }

        return $marcados;
    }

    /**
     * Apaga os contatos pelos ids.
     *
     * @param list<int> $ids
     *
     * @return int quantos foram apagados
     */
    public function apagarPorIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->getEntityManager()->createQuery('DELETE FROM ' . ContatoComercial::class . ' c WHERE c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->execute();
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function filtro(?string $busca): array
    {
        $busca = trim((string) $busca);
        if ($busca === '') {
            return ['TRUE', []];
        }

        return [
            "(unaccent(lower(c.email)) LIKE unaccent(lower(:busca)) OR unaccent(lower(COALESCE(c.nome, ''))) LIKE unaccent(lower(:busca)))",
            ['busca' => '%' . addcslashes($busca, '%_\\') . '%'],
        ];
    }
}
