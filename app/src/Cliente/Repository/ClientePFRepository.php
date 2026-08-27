<?php

namespace App\Cliente\Repository;

use App\Cliente\Entity\ClientePF;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClientePF>
 */
class ClientePFRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClientePF::class);
    }

    /**
     * Procura o cliente PF de um CPF DENTRO do escritório, aceitando as duas convenções de gravação
     * que convivem no banco: com máscara (`000.000.000-00`, o que a tela produz) e só dígitos (o que
     * a importação da cobrança gravou). Comparar apenas a string crua criaria o duplicado que a
     * unicidade por escritório existe para evitar.
     *
     * O `tenant` entra EXPLICITAMENTE no critério, e não só pelo TenantFilter de sessão: em CLI e
     * super-admin o filtro está desligado, e sem ele esta busca enxergaria cliente de outro
     * escritório — vazando existência alheia e bloqueando um cadastro legítimo.
     *
     * @param string $digitos CPF já reduzido a dígitos (ver NormalizadorDocumento::apenasDigitos)
     */
    public function findOneByCpfDoTenant(string $digitos, Tenant $tenant): ?ClientePF
    {
        $mascarado = \strlen($digitos) === 11
            ? sprintf('%s.%s.%s-%s', substr($digitos, 0, 3), substr($digitos, 3, 3), substr($digitos, 6, 3), substr($digitos, 9, 2))
            : $digitos;

        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.cpf IN (:formas)')
            ->setParameter('tenant', $tenant)
            ->setParameter('formas', array_unique([$digitos, $mascarado]))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(ClientePF $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ClientePF $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
