<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistCobranca;
use App\Pasta\Entity\PastaChecklistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Registros de cobrança do checklist. O comando diário roda sem usuário (filtro de tenant desligado):
 * toda consulta daqui recebe o escritório ou a pasta explicitamente.
 *
 * @extends ServiceEntityRepository<PastaChecklistCobranca>
 */
class PastaChecklistCobrancaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaChecklistCobranca::class);
    }

    public function salvar(PastaChecklistCobranca $cobranca, bool $flush = false): void
    {
        $this->getEntityManager()->persist($cobranca);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PastaChecklistCobranca $cobranca): void
    {
        $this->getEntityManager()->remove($cobranca);
    }

    public function doItem(PastaChecklistItem $item): ?PastaChecklistCobranca
    {
        return $this->findOneBy(['item' => $item, 'tenant' => $item->getTenant()]);
    }

    /**
     * Os registros dos itens de uma pasta, indexados pelo id do item.
     *
     * @return array<int, PastaChecklistCobranca>
     */
    public function daPasta(int $tenantId, int $pastaId): array
    {
        $lista = $this->createQueryBuilder('c')
            ->join('c.item', 'i')->addSelect('i')
            ->andWhere('IDENTITY(c.tenant) = :tenant')->setParameter('tenant', $tenantId)
            ->andWhere('IDENTITY(i.pasta) = :pasta')->setParameter('pasta', $pastaId)
            ->getQuery()->getResult();

        $porItem = [];
        foreach ($lista as $cobranca) {
            $porItem[(int) $cobranca->getItem()->getId()] = $cobranca;
        }

        return $porItem;
    }

    /**
     * Os ids das pastas do escritório que a rotina diária olha: não excluídas, em andamento (arquivada e
     * cancelada ficam fora, como nas listas de trabalho do Expediente) e com algum item de checklist ou com o
     * checklist desativado (o lembrete de 30 dias não depende de haver itens). Ids, e não entidades, para a
     * rotina carregar e soltar uma pasta por vez. Tenant explícito: roda sem sessão.
     *
     * @return list<int>
     */
    public function idsDasPastasDaRotina(int $tenantId): array
    {
        $ids = $this->getEntityManager()->createQueryBuilder()
            ->select('p.id')->from(Pasta::class, 'p')
            ->andWhere('IDENTITY(p.tenant) = :tenant')->setParameter('tenant', $tenantId)
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('p.situacao NOT IN (:fora)')->setParameter('fora', [Pasta::SITUACAO_ARQUIVADA, Pasta::SITUACAO_CANCELADA])
            ->andWhere('p.checklistDesativadoEm IS NOT NULL OR EXISTS (SELECT i.id FROM ' . PastaChecklistItem::class . ' i WHERE i.pasta = p)')
            ->orderBy('p.id', 'ASC')
            ->getQuery()->getSingleColumnResult();

        return array_map('intval', $ids);
    }

    /** @return list<int> escritórios ativos com alguma pasta que a rotina olha (itens ou checklist desativado) */
    public function escritoriosComChecklist(): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT p.tenant_id FROM pasta p JOIN tenant t ON t.id = p.tenant_id
              WHERE t.is_active = true AND t.excluido_em IS NULL AND p.excluida_em IS NULL
                AND (p.checklist_desativado_em IS NOT NULL OR EXISTS (SELECT 1 FROM pasta_checklist_item i WHERE i.pasta_id = p.id))
              ORDER BY p.tenant_id',
        );

        return array_map('intval', $ids);
    }
}
