<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Assinaturas de espaço. A entidade é TenantAware: na tela do escritório o filtro de tenant já restringe.
 * O webhook e a conciliação rodam sem usuário (filtro desligado) e por isso todas as consultas daqui
 * filtram o tenant explicitamente quando recebem um; a lista do Master passa pela `VisaoDoMaster`.
 *
 * @extends ServiceEntityRepository<AssinaturaDeEspaco>
 */
class AssinaturaDeEspacoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssinaturaDeEspaco::class);
    }

    public function salvar(AssinaturaDeEspaco $assinatura, bool $flush = false): void
    {
        $this->getEntityManager()->persist($assinatura);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(AssinaturaDeEspaco $assinatura, bool $flush = false): void
    {
        $this->getEntityManager()->remove($assinatura);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function doEscritorio(int $tenantId, int $id): ?AssinaturaDeEspaco
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.id = :id')->setParameter('id', $id)
            ->andWhere('IDENTITY(a.tenant) = :tenant')->setParameter('tenant', $tenantId)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<AssinaturaDeEspaco> as do escritório, da mais nova para a mais antiga */
    public function doTenant(int $tenantId): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.plano', 'p')->addSelect('p')
            ->andWhere('IDENTITY(a.tenant) = :tenant')->setParameter('tenant', $tenantId)
            ->orderBy('a.criadaEm', 'DESC')->addOrderBy('a.id', 'DESC')
            ->getQuery()->getResult();
    }

    public function porIdNoGateway(string $gateway, string $idDaAssinatura): ?AssinaturaDeEspaco
    {
        return $this->findOneBy(['gateway' => $gateway, 'idDaAssinatura' => $idDaAssinatura]);
    }

    /**
     * Os pedidos ainda sem assinatura ligada, do mais novo para o mais antigo: é a eles que um aviso de
     * "assinatura criada" se prende (o Asaas não devolve a referência do pedido, só o cliente). Inclui o
     * pedido que o Master confirmou à mão antes do aviso chegar (Ativa sem assinatura ligada).
     *
     * @return list<AssinaturaDeEspaco>
     */
    public function semAssinaturaNoGateway(int $tenantId, string $gateway): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.tenant) = :tenant')->setParameter('tenant', $tenantId)
            ->andWhere('a.gateway = :gateway')->setParameter('gateway', $gateway)
            ->andWhere('a.idDaAssinatura IS NULL')
            ->andWhere('a.situacao IN (:abertas)')->setParameter('abertas', [SituacaoDaAssinatura::Aguardando, SituacaoDaAssinatura::Ativa])
            ->orderBy('a.criadaEm', 'DESC')->addOrderBy('a.id', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * O que a conciliação reconsulta: tudo que tem assinatura no gateway e ainda pode mudar.
     *
     * @return list<AssinaturaDeEspaco>
     */
    public function paraConciliar(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.idDaAssinatura IS NOT NULL')
            ->andWhere('a.situacao IN (:abertas)')
            ->setParameter('abertas', [SituacaoDaAssinatura::Aguardando, SituacaoDaAssinatura::Ativa, SituacaoDaAssinatura::Inadimplente])
            // A mais antiga revista primeiro, sempre na mesma ordem.
            ->orderBy('a.atualizadaEm', 'ASC')->addOrderBy('a.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Assinaturas ainda vivas de escritórios excluídos (soft delete, quarentena de 365 dias): o gateway
     * seguiria cobrando o cartão durante a quarentena inteira.
     *
     * @return list<AssinaturaDeEspaco>
     */
    public function vivasDeEscritoriosExcluidos(): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.tenant', 't')->addSelect('t')
            ->andWhere('t.excluidoEm IS NOT NULL')
            ->andWhere('a.situacao IN (:vivas)')
            ->setParameter('vivas', [SituacaoDaAssinatura::Aguardando, SituacaoDaAssinatura::Ativa, SituacaoDaAssinatura::Inadimplente])
            ->getQuery()->getResult();
    }

    /** @return list<int> escritórios com alguma assinatura que já valeu (para revisar o plano vigente) */
    public function escritoriosComAssinaturaPaga(): array
    {
        $linhas = $this->createQueryBuilder('a')
            ->select('DISTINCT IDENTITY(a.tenant) AS tenant')
            ->andWhere('a.pagaAte IS NOT NULL')
            ->getQuery()->getScalarResult();

        return array_map(static fn (array $linha): int => (int) $linha['tenant'], $linhas);
    }

    /** @return list<AssinaturaDeEspaco> os últimos pedidos de todos os escritórios (modal do Master) */
    public function ultimos(int $quantos): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.plano', 'p')->addSelect('p')
            ->join('a.tenant', 't')->addSelect('t')
            ->orderBy('a.criadaEm', 'DESC')->addOrderBy('a.id', 'DESC')
            ->setMaxResults($quantos)
            ->getQuery()->getResult();
    }
}
