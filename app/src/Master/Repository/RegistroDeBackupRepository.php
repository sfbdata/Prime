<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\RegistroDeBackup;
use App\Master\Enum\TipoDeRegistroDeBackup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Backups e testes de restauração registrados pelo Master. GLOBAL, sem tenant (ver {@see RegistroDeBackup}).
 *
 * @extends ServiceEntityRepository<RegistroDeBackup>
 */
class RegistroDeBackupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistroDeBackup::class);
    }

    public function salvar(RegistroDeBackup $registro, bool $flush = false): void
    {
        $this->getEntityManager()->persist($registro);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<RegistroDeBackup> os mais recentes primeiro */
    public function recentes(int $limite): array
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.registradoEm', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /** Último backup CONCLUÍDO: backup que falhou não zera o relógio do indicador (desenho: `ub`). */
    public function ultimoBackupConcluido(): ?RegistroDeBackup
    {
        return $this->ultimo(TipoDeRegistroDeBackup::Backup, apenasOk: true);
    }

    /** Último teste de restauração, com ou sem sucesso (desenho: `ut`). */
    public function ultimaRestauracao(): ?RegistroDeBackup
    {
        return $this->ultimo(TipoDeRegistroDeBackup::Restauracao, apenasOk: false);
    }

    private function ultimo(TipoDeRegistroDeBackup $tipo, bool $apenasOk): ?RegistroDeBackup
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.tipo = :tipo')
            ->setParameter('tipo', $tipo)
            ->orderBy('b.registradoEm', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setMaxResults(1);
        if ($apenasOk) {
            $qb->andWhere('b.ok = true');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
