<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Prazos e audiências registrados à mão nas pastas. Toda consulta é pelo escritório.
 *
 * @extends ServiceEntityRepository<PastaPrazo>
 */
class PastaPrazoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaPrazo::class);
    }

    /**
     * Os prazos que o teor de uma publicação gerou no Push (todas as pastas do escritório com o processo).
     *
     * @return list<PastaPrazo>
     */
    public function doPushDaPublicacao(int $publicacaoId, Tenant $tenant): array
    {
        return $this->findBy([
            'tenant' => $tenant,
            'origem' => PastaPrazo::ORIGEM_PUSH,
            'registroRef' => 'push:' . $publicacaoId,
        ]);
    }

    public function findDaPasta(int $id, Pasta $pasta, Tenant $tenant): ?PastaPrazo
    {
        return $this->createQueryBuilder('z')
            ->andWhere('z.id = :id')
            ->andWhere('z.pasta = :pasta')
            ->andWhere('z.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Prazos não cumpridos da pasta, do mais próximo ao mais distante.
     *
     * @return PastaPrazo[]
     */
    public function abertosDaPasta(Pasta $pasta, Tenant $tenant): array
    {
        return $this->createQueryBuilder('z')
            ->andWhere('z.pasta = :pasta')
            ->andWhere('z.tenant = :tenant')
            ->andWhere('z.cumpridoEm IS NULL')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('z.data', 'ASC')
            ->addOrderBy('z.hora', 'ASC')
            ->addOrderBy('z.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Prazos e audiências não cumpridos do escritório no período, de pastas fora da lixeira, para
     * a Agenda. Traz a pasta junto (a Agenda mostra o NUP e o link). `$pastasOcultas`: as pastas ocultas pelo
     * cadeado para quem olha (spec pasta-cadeado D3) ficam de fora.
     *
     * @param list<int> $pastasOcultas
     *
     * @return list<PastaPrazo>
     */
    public function abertosDoEscritorioNoPeriodo(Tenant $tenant, \DateTimeImmutable $de, \DateTimeImmutable $ate, array $pastasOcultas = []): array
    {
        $qb = $this->createQueryBuilder('z')
            ->addSelect('p')
            ->join('z.pasta', 'p')
            ->andWhere('z.tenant = :tenant')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('z.cumpridoEm IS NULL')
            ->andWhere('z.data >= :de AND z.data <= :ate')
            ->setParameter('tenant', $tenant)
            ->setParameter('de', $de->setTime(0, 0))
            ->setParameter('ate', $ate->setTime(0, 0))
            ->orderBy('z.data', 'ASC')
            ->addOrderBy('z.hora', 'ASC')
            ->addOrderBy('z.id', 'ASC');
        if ($pastasOcultas !== []) {
            $qb->andWhere('p.id NOT IN (:pastasOcultas)')->setParameter('pastasOcultas', $pastasOcultas);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Prazos e audiências do escritório no período, cumpridos ou não, de pastas fora da lixeira, para a
     * grade da Agenda (o desenho 09 mostra todo prazo com data; o cumprido aparece como concluído). Os
     * lembretes continuam com `abertosDoEscritorioNoPeriodo`. `$pastasOcultas`: ids de pasta que quem olha
     * não vê (cadeado da pasta, #120).
     *
     * @param list<int> $pastasOcultas
     *
     * @return list<PastaPrazo>
     */
    public function doEscritorioNoPeriodo(Tenant $tenant, \DateTimeImmutable $de, \DateTimeImmutable $ate, array $pastasOcultas = []): array
    {
        $consulta = $this->createQueryBuilder('z')
            ->addSelect('p')
            ->join('z.pasta', 'p')
            ->andWhere('z.tenant = :tenant')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('z.data >= :de AND z.data <= :ate')
            ->setParameter('tenant', $tenant)
            ->setParameter('de', $de->setTime(0, 0))
            ->setParameter('ate', $ate->setTime(0, 0))
            ->orderBy('z.data', 'ASC')
            ->addOrderBy('z.hora', 'ASC')
            ->addOrderBy('z.id', 'ASC');
        if ([] !== $pastasOcultas) {
            $consulta->andWhere('p.id NOT IN (:ocultas)')->setParameter('ocultas', $pastasOcultas);
        }

        return $consulta->getQuery()->getResult();
    }

    /** Um prazo do escritório, de pasta fora da lixeira (o "Avisar no Chat" da Agenda chega só com o id). */
    public function doEscritorio(int $id, Tenant $tenant): ?PastaPrazo
    {
        return $this->createQueryBuilder('z')
            ->addSelect('p')
            ->join('z.pasta', 'p')
            ->andWhere('z.id = :id')
            ->andWhere('z.tenant = :tenant')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
