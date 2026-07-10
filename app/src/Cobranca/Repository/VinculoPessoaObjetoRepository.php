<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VinculoPessoaObjeto>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class VinculoPessoaObjetoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VinculoPessoaObjeto::class);
    }

    public function salvar(VinculoPessoaObjeto $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(VinculoPessoaObjeto $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?VinculoPessoaObjeto
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Pessoas DISTINTAS vinculadas a um objeto (suporte à dedup da importação — decisão A: não recriar
     * a Pessoa do sacado já vinculada ao objeto na reimportação). Escopo por tenant do objeto (defesa
     * em profundidade).
     *
     * @return Pessoa[]
     */
    public function pessoasVinculadasAoObjeto(ObjetoCobranca $objeto): array
    {
        $vinculos = $this->createQueryBuilder('v')
            ->andWhere('v.objeto = :objeto')
            ->andWhere('v.tenant = :tenant')
            ->setParameter('objeto', $objeto)
            ->setParameter('tenant', $objeto->getTenant())
            ->getQuery()
            ->getResult();

        $pessoas = [];
        $vistos = [];
        foreach ($vinculos as $vinculo) {
            $pessoa = $vinculo->getPessoa();
            $id = $pessoa?->getId();
            if ($pessoa !== null && !isset($vistos[$id])) {
                $vistos[$id] = true;
                $pessoas[] = $pessoa;
            }
        }

        return $pessoas;
    }
}
