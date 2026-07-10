<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ObjetoCobranca>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class ObjetoCobrancaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ObjetoCobranca::class);
    }

    public function salvar(ObjetoCobranca $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(ObjetoCobranca $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?ObjetoCobranca
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Objeto de uma carteira por sua identificação (dedup da importação — decisão C). Escopo por
     * tenant SEMPRE (a carteira já é tenant-bound; o tenant é defesa em profundidade). Usado para NÃO
     * recriar o mesmo objeto (unidade) na reimportação.
     */
    public function findOnePorIdentificacaoNaCarteira(Carteira $carteira, string $identificacao, Tenant $tenant): ?ObjetoCobranca
    {
        return $this->findOneBy([
            'carteira' => $carteira,
            'identificacao' => $identificacao,
            'tenant' => $tenant,
        ]);
    }
}
