<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\ContatoComercial;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
