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

    /**
     * TODOS os vínculos de um objeto (abertos E encerrados — histórico preservado, invariável 11), com a
     * PESSOA fetch-joined (`addSelect`) para a aba "Pessoas" da página unificada do objeto (ajuste 2). Sem
     * o fetch-join, ler `pessoa->getNome()` por vínculo dispararia hidratação lazy (N+1). `pessoa` é
     * `nullable: false` → `innerJoin` seguro. Escopo por tenant do objeto (defesa em profundidade). Mais
     * antigos primeiro (a linha do tempo do vínculo).
     *
     * @return VinculoPessoaObjeto[]
     */
    public function todosDoObjetoComPessoa(ObjetoCobranca $objeto): array
    {
        return $this->createQueryBuilder('v')
            ->innerJoin('v.pessoa', 'p')
            ->addSelect('p')
            ->andWhere('v.objeto = :objeto')
            ->andWhere('v.tenant = :tenant')
            ->setParameter('objeto', $objeto)
            ->setParameter('tenant', $objeto->getTenant())
            ->orderBy('v.dataInicio', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vínculos ABERTOS (dataFim NULL) dos objetos informados, com a PESSOA fetch-joined (`addSelect`) —
     * para a visão da carteira ler `pessoa->getNome()` por vínculo sem disparar hidratação lazy (N+1).
     * `pessoa` é `nullable: false` → `innerJoin` seguro (não descarta linhas). Escopo por tenant explícito.
     * `$objetos` vazio → `[]`.
     *
     * @param ObjetoCobranca[] $objetos
     *
     * @return VinculoPessoaObjeto[]
     */
    public function abertosDosObjetosComPessoa(array $objetos, Tenant $tenant): array
    {
        if ($objetos === []) {
            return [];
        }

        return $this->createQueryBuilder('v')
            ->innerJoin('v.pessoa', 'p')
            ->addSelect('p')
            ->andWhere('v.objeto IN (:objetos)')
            ->andWhere('v.tenant = :tenant')
            ->andWhere('v.dataFim IS NULL')
            ->setParameter('objetos', $objetos)
            ->setParameter('tenant', $tenant)
            ->orderBy('v.dataInicio', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
