<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\ServiceDesk\Repository\SemFiltroDeTenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Folhas emitidas com código BJF (E16). A consulta por código é a da validação PÚBLICA, como a do comprovante (E15):
 * atravessa os escritórios de propósito (quem confere pode estar sem login ou logado em OUTRO escritório) e devolve a
 * linha para a conferência do HMAC. As demais consultas levam o escritório escrito à mão.
 *
 * @extends ServiceEntityRepository<FolhaEmitidaDoPonto>
 */
class FolhaEmitidaDoPontoRepository extends ServiceEntityRepository
{
    use SemFiltroDeTenant;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FolhaEmitidaDoPonto::class);
    }

    /** Só persiste: quem emite decide o flush (a emissão e o evento da trilha saem na mesma transação). */
    public function adicionar(FolhaEmitidaDoPonto $folha): void
    {
        $this->getEntityManager()->persist($folha);
    }

    public function doCodigo(string $codigo): ?FolhaEmitidaDoPonto
    {
        return $this->semFiltroDeTenant(fn (): ?FolhaEmitidaDoPonto => $this->findOneBy(['codigo' => $codigo]));
    }

    public function existeCodigo(string $codigo): bool
    {
        return $this->doCodigo($codigo) !== null;
    }

    /** A emissão mais nova da folha da pessoa NESTE escritório e nesta competência; null se nunca foi emitida. */
    public function ultimaDaCompetencia(Tenant $escritorio, User $pessoa, int $ano, int $mes): ?FolhaEmitidaDoPonto
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.tenant = :escritorio')
            ->andWhere('f.user = :pessoa')
            ->andWhere('f.ano = :ano')
            ->andWhere('f.mes = :mes')
            ->setParameter('escritorio', $escritorio)
            ->setParameter('pessoa', $pessoa)
            ->setParameter('ano', $ano)
            ->setParameter('mes', $mes)
            ->orderBy('f.emitidaEm', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
