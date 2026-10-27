<?php

declare(strict_types=1);

namespace App\Tarefa\Repository;

use App\Entity\Tenant\Tenant;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CaixaDaEsteira> */
class CaixaDaEsteiraRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CaixaDaEsteira::class);
    }

    /**
     * As caixas que o escritório mudou, com os avisados já carregados (uma consulta para a esteira inteira).
     *
     * @return array<string, CaixaDaEsteira> chave = valor de `EtapaDaPasta`
     */
    public function configuradasDoEscritorio(Tenant $tenant): array
    {
        /** @var CaixaDaEsteira[] $caixas */
        $caixas = $this->createQueryBuilder('c')
            ->addSelect('a')
            ->leftJoin('c.avisados', 'a')
            // O perfil vem junto: o gênero dá o "Dr./Dra." do avisado (spec genero-no-cadastro, regra 4).
            ->addSelect('ap')
            ->leftJoin('a.profile', 'ap')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('a.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        $porEtapa = [];
        foreach ($caixas as $caixa) {
            $porEtapa[$caixa->getEtapa()->value] = $caixa;
        }

        return $porEtapa;
    }

    public function daEtapa(Tenant $tenant, EtapaDaPasta $etapa): ?CaixaDaEsteira
    {
        return $this->findOneBy(['tenant' => $tenant, 'etapa' => $etapa]);
    }

    public function salvar(CaixaDaEsteira $caixa, bool $flush = false): void
    {
        $this->getEntityManager()->persist($caixa);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(CaixaDaEsteira $caixa, bool $flush = false): void
    {
        $this->getEntityManager()->remove($caixa);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
