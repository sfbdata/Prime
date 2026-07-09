<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Pessoa;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pessoa>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class PessoaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pessoa::class);
    }

    public function salvar(Pessoa $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(Pessoa $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca tenant-safe por id — o filtro SQL do Doctrine NÃO se aplica a find() por PK
     * (risco cross-tenant). Sempre passar o tenant explícito.
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?Pessoa
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Pessoas do MESMO tenant cujo CPF ou CNPJ coincide com os informados — suporte à
     * sugestão advisory de duplicidades (SPEC §7/§24). Escopo intra-tenant SEMPRE; nunca
     * atravessa escritórios (invariável 24). Sem documentos informados, retorna vazio.
     *
     * @return Pessoa[]
     */
    public function buscarPossiveisDuplicadas(Tenant $tenant, ?string $cpf, ?string $cnpj): array
    {
        if ($cpf === null && $cnpj === null) {
            return [];
        }

        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $orX = $qb->expr()->orX();
        if ($cpf !== null) {
            $orX->add('p.cpf = :cpf');
            $qb->setParameter('cpf', $cpf);
        }
        if ($cnpj !== null) {
            $orX->add('p.cnpj = :cnpj');
            $qb->setParameter('cnpj', $cnpj);
        }

        return $qb->andWhere($orX)->getQuery()->getResult();
    }
}
