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
     * As pastas do escritório que a rotina diária olha: não excluídas e com algum item de checklist (as
     * desativadas entram para o lembrete de 30 dias). Tenant explícito: roda sem sessão.
     *
     * @return list<Pasta>
     */
    public function pastasComChecklist(int $tenantId): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('p')->from(Pasta::class, 'p')
            ->andWhere('IDENTITY(p.tenant) = :tenant')->setParameter('tenant', $tenantId)
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('EXISTS (SELECT i.id FROM ' . PastaChecklistItem::class . ' i WHERE i.pasta = p)')
            ->orderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<int> escritórios ativos com algum item de checklist */
    public function escritoriosComChecklist(): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT i.tenant_id FROM pasta_checklist_item i JOIN tenant t ON t.id = i.tenant_id WHERE t.is_active = true AND t.excluido_em IS NULL ORDER BY i.tenant_id',
        );

        return array_map('intval', $ids);
    }
}
