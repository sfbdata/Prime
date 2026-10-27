<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\SubsecaoOab;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Subseções da OAB cadastradas pelo Master. Sem filtro de tenant de propósito: tabela de PLATAFORMA
 * (ver {@see SubsecaoOab}), lida e gravada só pelos UseCases do Master, atrás de ROLE_SUPER_ADMIN.
 *
 * @extends ServiceEntityRepository<SubsecaoOab>
 */
class SubsecaoOabRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubsecaoOab::class);
    }

    /** A subseção só vale se for da seccional da URL: id de outra UF não é encontrado (nada de remover pela UF errada). */
    public function buscarNaSeccional(int $id, string $uf): ?SubsecaoOab
    {
        /** @var ?SubsecaoOab */
        return $this->createQueryBuilder('x')
            ->join('x.seccional', 's')
            ->andWhere('x.id = :id')->setParameter('id', $id)
            ->andWhere('s.uf = :uf')->setParameter('uf', $uf)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Todas as subseções, por UF, na ordem de cadastro. Uma consulta só para as 27, sem carregar entidades.
     *
     * @return array<string, list<array{id: int, cidade: ?string, nome: string, endereco: string, telefone: ?string}>> UF => lista
     */
    public function todasPorUf(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT s.uf, x.id, x.cidade, x.nome, x.endereco, x.telefone
               FROM master_subsecao_oab x
               JOIN master_seccional_oab s ON s.id = x.seccional_id
           ORDER BY s.uf, x.id',
        );

        $porUf = [];
        foreach ($linhas as $l) {
            $porUf[(string) $l['uf']][] = [
                'id'       => (int) $l['id'],
                'cidade'   => $l['cidade'] !== null ? (string) $l['cidade'] : null,
                'nome'     => (string) $l['nome'],
                'endereco' => (string) $l['endereco'],
                'telefone' => $l['telefone'] !== null ? (string) $l['telefone'] : null,
            ];
        }

        return $porUf;
    }

    public function salvar(SubsecaoOab $subsecao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($subsecao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(SubsecaoOab $subsecao, bool $flush = false): void
    {
        $this->getEntityManager()->remove($subsecao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
