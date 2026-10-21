<?php

declare(strict_types=1);

namespace App\Tenant\Repository;

use App\Tenant\Entity\SaidaDaEquipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SaidaDaEquipe> */
class SaidaDaEquipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaidaDaEquipe::class);
    }

    public function registrar(SaidaDaEquipe $saida): void
    {
        $this->getEntityManager()->persist($saida);
    }

    /**
     * Leitor para o painel do Master (b5): quantas saídas houve no período, em todos os escritórios. SQL direto
     * de propósito: o painel é da plataforma, sem escritório na sessão, e o TenantFilter não se aplica a ele.
     */
    public function contarNoPeriodo(\DateTimeImmutable $de, \DateTimeImmutable $ate, bool $soPrimarios = false): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM saida_da_equipe WHERE saiu_em >= :de AND saiu_em < :ate'
                . ($soPrimarios ? ' AND era_primario = true' : ''),
            ['de' => $de->format('Y-m-d H:i:s'), 'ate' => $ate->format('Y-m-d H:i:s')],
        );
    }
}
