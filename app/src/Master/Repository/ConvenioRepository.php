<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\Convenio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os convênios do BlueJus (`convenio`, spec docs/specs/convenios.md, §3.1). Sem filtro de tenant de propósito: é
 * configuração GLOBAL da plataforma (ver {@see Convenio}).
 *
 * @extends ServiceEntityRepository<Convenio>
 */
class ConvenioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Convenio::class);
    }

    public function porChave(string $chave): ?Convenio
    {
        return $this->findOneBy(['chave' => $chave]);
    }

    /** @return list<Convenio> o OAB primeiro, depois por nome */
    public function todos(): array
    {
        /** @var list<Convenio> $lista */
        $lista = $this->createQueryBuilder('c')
            ->addSelect("CASE WHEN c.chave = :oab THEN 0 ELSE 1 END AS HIDDEN ordem")
            ->setParameter('oab', Convenio::CHAVE_OAB)
            ->orderBy('ordem', 'ASC')
            ->addOrderBy('c.nome', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $lista;
    }

    /** @return list<Convenio> os convênios da mesma instituição (desligar por instituição, §6.1) */
    public function daInstituicao(string $instituicaoChave): array
    {
        /** @var list<Convenio> $lista */
        $lista = $this->findBy(['instituicaoChave' => $instituicaoChave], ['id' => 'ASC']);

        return $lista;
    }

    /** @return list<Convenio> ligados com o dia do fim já chegado (a tarefa diária desliga, §6.2) */
    public function comFimAte(\DateTimeImmutable $hoje): array
    {
        /** @var list<Convenio> $lista */
        $lista = $this->createQueryBuilder('c')
            ->andWhere('c.ativo = true')
            ->andWhere('c.encerraEm IS NOT NULL')
            ->andWhere('c.encerraEm <= :hoje')
            ->setParameter('hoje', $hoje->setTime(0, 0), 'date_immutable')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $lista;
    }

    public function existeChave(string $chave): bool
    {
        return $this->count(['chave' => $chave]) > 0;
    }

    public function existeCodigo(string $codigo): bool
    {
        return $this->count(['codigoConvite' => $codigo]) > 0;
    }

    public function salvar(Convenio $convenio, bool $flush = false): void
    {
        $this->getEntityManager()->persist($convenio);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
