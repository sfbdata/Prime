<?php

declare(strict_types=1);

namespace App\Cliente\Repository;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteEmail;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os e-mails do cliente do "Avisar cliente" (spec push-avisar-cliente §1). Tenant explícito em toda
 * consulta, sem depender do TenantFilter.
 *
 * @extends ServiceEntityRepository<ClienteEmail>
 */
// Não-final: os testes de UseCase mockam o repositório do domínio.
class ClienteEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClienteEmail::class);
    }

    /**
     * TODAS as linhas do cliente, inclusive a do cadastro marcada como removida (o caderno precisa
     * dela para não ressuscitar o item virtual), em ordem de criação.
     *
     * @return list<ClienteEmail>
     */
    public function listarDoCliente(Tenant $tenant, Cliente $cliente): array
    {
        /** @var list<ClienteEmail> $linhas */
        $linhas = $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.cliente = :cliente')
            ->setParameter('tenant', $tenant)
            ->setParameter('cliente', $cliente)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $linhas;
    }

    /**
     * Grava o resultado de uma operação do caderno: as linhas novas e as que saem. As alteradas já
     * estão gerenciadas e vão no mesmo flush.
     *
     * @param list<ClienteEmail> $novas
     * @param list<ClienteEmail> $apagadas
     */
    public function gravar(array $novas, array $apagadas, bool $flush = false): void
    {
        foreach ($novas as $email) {
            $this->getEntityManager()->persist($email);
        }
        foreach ($apagadas as $email) {
            $this->getEntityManager()->remove($email);
        }
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
