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
            // Desempate: o backfill gravou o mesmo criado_em para todas, e sem ele as páginas repetem ou pulam linhas.
            ->addOrderBy('i.id', 'DESC')
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

    /**
     * Cria a principal a partir do espelho User.oab* se a pessoa ainda não tem inscrição. SQL com ON CONFLICT: duas
     * requisições ao mesmo tempo (duas abas na primeira abertura) não estouram o índice único, a segunda só não faz nada.
     */
    public function criarPrincipalDoEspelhoSeFaltar(User $usuario): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO inscricao_oab (user_id, numero, uf, tipo, status, nome_oficial, verificada_em, criado_em)
                SELECT u.id, TRIM(u.oab_numero), UPPER(TRIM(u.oab_uf)), 'principal', COALESCE(u.oab_status, 'nao_verificada'),
                       u.oab_nome_oficial, u.oab_verificada_em, NOW()
                  FROM "user" u
                 WHERE u.id = :id AND COALESCE(TRIM(u.oab_numero), '') <> '' AND COALESCE(TRIM(u.oab_uf), '') <> ''
                   AND NOT EXISTS (SELECT 1 FROM inscricao_oab i WHERE i.user_id = u.id)
                ON CONFLICT DO NOTHING
                SQL,
            ['id' => $usuario->getId()],
        );
    }

    /**
     * Grava `$nova` como principal rebaixando as outras ANTES, numa transação. O Doctrine faz INSERT antes de UPDATE
     * no mesmo flush, e o índice único parcial da principal recusaria a nova enquanto a antiga ainda é principal.
     *
     * @param list<InscricaoOab> $rebaixar
     */
    public function gravarComNovaPrincipal(InscricaoOab $nova, array $rebaixar): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(static function () use ($em, $nova, $rebaixar): void {
            foreach ($rebaixar as $antiga) {
                $antiga->tornarSuplementar();
            }
            $nova->tornarSuplementar();
            $em->persist($nova);
            $em->flush();
            $nova->tornarPrincipal();
            $nova->espelharNoUsuario();
            $em->flush();
        });
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
