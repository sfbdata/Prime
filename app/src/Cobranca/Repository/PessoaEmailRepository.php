<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PessoaEmail>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class PessoaEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PessoaEmail::class);
    }

    public function salvar(PessoaEmail $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca tenant-safe por id — o filtro SQL do Doctrine NÃO se aplica a find() por PK
     * (risco cross-tenant). Sempre passar o tenant explícito.
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?PessoaEmail
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /** Lista os e-mails da pessoa em ordem de linha do tempo (`criadoEm ASC`). */
    public function listarPorPessoa(Pessoa $pessoa): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.pessoa = :pessoa')
            ->setParameter('pessoa', $pessoa)
            ->orderBy('e.criadoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** O e-mail marcado como `atual` da pessoa (no máximo um, pela invariante do UseCase). */
    public function buscarAtualDaPessoa(Pessoa $pessoa): ?PessoaEmail
    {
        return $this->findOneBy(['pessoa' => $pessoa, 'atual' => true]);
    }

    /** Verdadeiro se a pessoa já tem pelo menos um e-mail cadastrado. */
    public function existePeloMenosUmDaPessoa(Pessoa $pessoa): bool
    {
        $total = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.pessoa = :pessoa')
            ->setParameter('pessoa', $pessoa)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $total) > 0;
    }
}
