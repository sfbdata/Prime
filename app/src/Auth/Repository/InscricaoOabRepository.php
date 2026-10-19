<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Entity\Auth\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Leitor público das inscrições na OAB (spec docs/specs/meu-perfil-oab-inscricoes.md §3): o perfil usa
 * `doUsuario`; a revisão do Master (frente b5) usa `paraRevisao`/`contarParaRevisao`, que são GLOBAIS
 * (a inscrição é da pessoa, não do escritório) e só podem ser chamados por quem é Master.
 *
 * @extends ServiceEntityRepository<InscricaoOab>
 */
class InscricaoOabRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InscricaoOab::class);
    }

    /** @return list<InscricaoOab> principal primeiro, depois por UF (ordem do desenho) */
    public function doUsuario(User $usuario): array
    {
        /** @var list<InscricaoOab> $lista */
        $lista = $this->createQueryBuilder('i')
            ->andWhere('i.usuario = :usuario')
            ->setParameter('usuario', $usuario)
            ->addSelect('CASE WHEN i.tipo = :principal THEN 0 ELSE 1 END AS HIDDEN ordem')
            ->setParameter('principal', TipoInscricaoOab::Principal->value)
            ->orderBy('ordem', 'ASC')
            ->addOrderBy('i.uf', 'ASC')
            ->getQuery()
            ->getResult();

        return $lista;
    }

    public function daPessoa(int $id, User $usuario): ?InscricaoOab
    {
        return $this->findOneBy(['id' => $id, 'usuario' => $usuario]);
    }

    /**
     * Fila da revisão do Master por inscrição (principal e suplementares).
     *
     * @param list<StatusOab> $statuses
     *
     * @return list<InscricaoOab>
     */
    public function paraRevisao(array $statuses, string $busca = '', int $pagina = 1, int $porPagina = 30): array
    {
        /** @var list<InscricaoOab> $lista */
        $lista = $this->qbRevisao($statuses, $busca)
            ->orderBy('i.criadoEm', 'DESC')
            ->setFirstResult((max(1, $pagina) - 1) * $porPagina)
            ->setMaxResults($porPagina)
            ->getQuery()
            ->getResult();

        return $lista;
    }

    /** @param list<StatusOab> $statuses */
    public function contarParaRevisao(array $statuses, string $busca = ''): int
    {
        return (int) $this->qbRevisao($statuses, $busca)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
    }

    public function salvar(InscricaoOab $inscricao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($inscricao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(InscricaoOab $inscricao, bool $flush = false): void
    {
        $this->getEntityManager()->remove($inscricao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @param list<StatusOab> $statuses */
    private function qbRevisao(array $statuses, string $busca): QueryBuilder
    {
        $qb = $this->createQueryBuilder('i')->join('i.usuario', 'u')->addSelect('u');
        if ($statuses !== []) {
            $qb->andWhere('i.status IN (:statuses)')->setParameter('statuses', $statuses);
        }
        $termo = trim($busca);
        if ($termo !== '') {
            $qb->andWhere('LOWER(u.fullName) LIKE :busca OR LOWER(u.email) LIKE :busca OR i.numero LIKE :busca')
                ->setParameter('busca', '%' . mb_strtolower($termo) . '%');
        }

        return $qb;
    }
}
