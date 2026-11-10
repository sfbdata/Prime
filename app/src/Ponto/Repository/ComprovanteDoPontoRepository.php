<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\ComprovanteDoPonto;
use App\ServiceDesk\Repository\SemFiltroDeTenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Comprovantes das batidas (E15). A consulta por código é a da validação PÚBLICA: atravessa os escritórios de
 * propósito (quem confere pode estar sem login ou logado em OUTRO escritório) e devolve a linha para a conferência do
 * HMAC. As demais consultas são da própria pessoa, no escritório escrito à mão.
 *
 * @extends ServiceEntityRepository<ComprovanteDoPonto>
 */
class ComprovanteDoPontoRepository extends ServiceEntityRepository
{
    use SemFiltroDeTenant;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComprovanteDoPonto::class);
    }

    /** Só persiste: a batida e o comprovante saem no mesmo flush, dentro da transação da batida. */
    public function adicionar(ComprovanteDoPonto $comprovante): void
    {
        $this->getEntityManager()->persist($comprovante);
    }

    public function porCodigo(string $codigo): ?ComprovanteDoPonto
    {
        return $this->semFiltroDeTenant(fn (): ?ComprovanteDoPonto => $this->findOneBy(['codigo' => $codigo]));
    }

    public function existeCodigo(string $codigo): bool
    {
        return $this->porCodigo($codigo) !== null;
    }

    /**
     * Os comprovantes da pessoa NESTE escritório, a batida mais nova primeiro (aba Comprovantes, P48). A pessoa é a da
     * própria linha: o comprovante da batida que o gestor apagou continua aqui. A batida só entra na ordem (a guardada
     * sem internet tem a hora do aparelho, mais velha que a emissão); sem ela, vale a hora da emissão.
     *
     * @return list<ComprovanteDoPonto>
     */
    public function daPessoaNoEscritorio(User $pessoa, Tenant $escritorio, int $limite): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('COALESCE(r.dataHora, c.emitidoEm) AS HIDDEN momento')
            ->leftJoin('c.registro', 'r')
            ->andWhere('c.tenant = :escritorio')
            ->andWhere('c.user = :pessoa')
            ->setParameter('escritorio', $escritorio)
            ->setParameter('pessoa', $pessoa)
            ->orderBy('momento', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * O comprovante pelo id, só se for da pessoa e deste escritório (guarda de posse do download: senão, null). Também
     * o da batida que o gestor apagou.
     */
    public function daPessoaPorId(int $id, User $pessoa, Tenant $escritorio): ?ComprovanteDoPonto
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.tenant = :escritorio')
            ->andWhere('c.user = :pessoa')
            ->setParameter('id', $id)
            ->setParameter('escritorio', $escritorio)
            ->setParameter('pessoa', $pessoa)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
