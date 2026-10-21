<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AcessoAPastaTrancada;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Cadeados das pastas. Toda consulta é escopada ao escritório (o da pasta ou o passado).
 *
 * @extends ServiceEntityRepository<CadeadoDaPasta>
 */
class CadeadoDaPastaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CadeadoDaPasta::class);
    }

    public function salvar(CadeadoDaPasta $cadeado, bool $flush = false): void
    {
        $this->getEntityManager()->persist($cadeado);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(CadeadoDaPasta $cadeado, bool $flush = false): void
    {
        $this->getEntityManager()->remove($cadeado);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O cadeado da pasta, já com a lista de pessoas (uma consulta só). */
    public function daPasta(Pasta $pasta): ?CadeadoDaPasta
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null || $pasta->getId() === null) {
            return null;
        }

        return $this->daPastaPorId((int) $pasta->getId(), $tenant);
    }

    public function daPastaPorId(int $pastaId, Tenant $tenant): ?CadeadoDaPasta
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a')
            ->leftJoin('c.acessos', 'a')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('IDENTITY(c.pasta) = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pastaId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Os cadeados de um lote de pastas, por id de pasta (`[pastaId => CadeadoDaPasta]`). Pasta sem
     * cadeado não aparece no mapa. É o que a Lista do Expediente usa: uma consulta para a página.
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, CadeadoDaPasta>
     */
    public function dasPastas(array $pastaIds, Tenant $tenant): array
    {
        if ($pastaIds === []) {
            return [];
        }

        $cadeados = $this->createQueryBuilder('c')
            ->addSelect('a')
            ->leftJoin('c.acessos', 'a')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('IDENTITY(c.pasta) IN (:pastas)')
            ->setParameter('tenant', $tenant)
            ->setParameter('pastas', array_values(array_unique($pastaIds)))
            ->getQuery()
            ->getResult();

        $mapa = [];
        foreach ($cadeados as $cadeado) {
            $mapa[(int) $cadeado->getPasta()->getId()] = $cadeado;
        }

        return $mapa;
    }

    /**
     * Existe cadeado nesta pasta? Sem escopo de sessão: a página pública do link externo do Push (sem
     * login) pergunta isso pela pasta do link, e o escritório é o da própria pasta.
     */
    public function existeNaPasta(Pasta $pasta): bool
    {
        if ($pasta->getId() === null) {
            return false;
        }

        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.pasta = :pasta')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $pasta->getTenant())
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Os números (como gravados) destes processos: o Push global (spec pasta-cadeado D3) some com os
     * processos que a pessoa não abre.
     *
     * @param list<int> $processoIds
     *
     * @return list<string>
     */
    public function numerosDosProcessos(array $processoIds): array
    {
        if ($processoIds === []) {
            return [];
        }

        $linhas = $this->getEntityManager()->createQueryBuilder()
            ->select('pr.numeroProcesso AS numero')
            ->from(Processo::class, 'pr')
            ->andWhere('pr.id IN (:ids)')
            ->setParameter('ids', $processoIds)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $l): string => (string) $l['numero'], $linhas));
    }

    /**
     * Ids das pastas com cadeado em que a pessoa NÃO está na lista de quem vê (Tarefas, auditoria, Drive).
     *
     * @return list<int>
     */
    public function idsDasPastasOcultasPara(User $pessoa, Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.pasta) AS pasta')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('NOT EXISTS (SELECT a.id FROM ' . AcessoAPastaTrancada::class . ' a WHERE a.cadeado = c AND c.modo = :porPessoa AND a.pessoa = :pessoa)')
            ->setParameter('tenant', $tenant)
            ->setParameter('pessoa', $pessoa)
            ->setParameter('porPessoa', ModoDoCadeado::PorPessoa)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $l): int => (int) $l['pasta'], $linhas));
    }

    /**
     * Os processos cujas pastas vinculadas estão TODAS entre estas (as ocultas para alguém): spec
     * pasta-cadeado K3, "processo" está no que o Oculto não vê. Processo com pelo menos uma pasta fora da
     * lista, ou sem pasta, não entra.
     *
     * @param list<int> $pastaIds
     *
     * @return list<int>
     */
    public function processosSoDestasPastas(array $pastaIds): array
    {
        if ($pastaIds === []) {
            return [];
        }

        $linhas = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(pp.processo) AS processo')
            ->from(PastaProcesso::class, 'pp')
            ->andWhere('IDENTITY(pp.pasta) IN (:pastas)')
            ->andWhere('NOT EXISTS (SELECT pp2.id FROM ' . PastaProcesso::class . ' pp2 WHERE pp2.processo = pp.processo AND IDENTITY(pp2.pasta) NOT IN (:pastas))')
            ->setParameter('pastas', $pastaIds)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $l): int => (int) $l['processo'], $linhas));
    }

    /** O escritório pelo id, sem consulta (referência do Doctrine), para quem só tem o id (SQL nativo). */
    public function escritorioPorId(int $tenantId): Tenant
    {
        return $this->getEntityManager()->getReference(Tenant::class, $tenantId);
    }

    /** @return list<int> ids de todas as pastas com cadeado do escritório (o sync do Drive, D6) */
    public function idsDasPastasComCadeado(Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.pasta) AS pasta')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $l): int => (int) $l['pasta'], $linhas));
    }
}
