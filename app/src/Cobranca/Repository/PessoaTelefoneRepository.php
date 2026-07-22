<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaTelefone;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PessoaTelefone>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class PessoaTelefoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PessoaTelefone::class);
    }

    public function salvar(PessoaTelefone $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?PessoaTelefone
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /** Lista os telefones da pessoa em ordem de linha do tempo (`criadoEm ASC`). */
    public function listarPorPessoa(Pessoa $pessoa): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.pessoa = :pessoa')
            ->setParameter('pessoa', $pessoa)
            ->orderBy('t.criadoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * O telefone marcado como `atual` da pessoa. Determinístico: se por algum motivo houver mais
     * de um `atual = true` (não deveria, mas os UseCases de marcação se auto-corrigem), o mais
     * recente (`criadoEm` maior) vence.
     */
    public function buscarAtualDaPessoa(Pessoa $pessoa): ?PessoaTelefone
    {
        return $this->findOneBy(['pessoa' => $pessoa, 'atual' => true], ['criadoEm' => 'DESC']);
    }

    /** Verdadeiro se a pessoa já tem pelo menos um telefone cadastrado. */
    public function existePeloMenosUmDaPessoa(Pessoa $pessoa): bool
    {
        $total = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.pessoa = :pessoa')
            ->setParameter('pessoa', $pessoa)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $total) > 0;
    }
}
