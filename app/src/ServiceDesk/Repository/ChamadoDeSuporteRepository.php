<?php

declare(strict_types=1);

namespace App\ServiceDesk\Repository;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Entity\ChamadoDeSuporte;
use App\ServiceDesk\Enum\StatusDoChamado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChamadoDeSuporte>
 */
class ChamadoDeSuporteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChamadoDeSuporte::class);
    }

    public function salvar(ChamadoDeSuporte $chamado, bool $flush = false): void
    {
        $this->getEntityManager()->persist($chamado);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function doEscritorio(Tenant $tenant, int $id): ?ChamadoDeSuporte
    {
        return $this->findOneBy(['tenant' => $tenant, 'id' => $id]);
    }

    /**
     * Próximo número do escritório. Chamar com o saldo travado (`SaldoDoEscritorioRepository::travarDoEscritorio`):
     * a trava serializa dois pedidos e o índice único (tenant, numero) é a rede de segurança.
     */
    public function proximoNumero(Tenant $tenant): int
    {
        $maior = $this->createQueryBuilder('c')
            ->select('MAX(c.numero)')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $maior + 1;
    }

    /**
     * Lista do escritório, mais recentes primeiro. A busca olha assunto, descrição e número ("#0001" ou "1").
     *
     * @return list<ChamadoDeSuporte>
     */
    public function listarDoEscritorio(Tenant $tenant, ?StatusDoChamado $status, string $busca = ''): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('c.criadoEm', 'DESC')
            ->addOrderBy('c.id', 'DESC');
        if (null !== $status) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }
        $busca = trim($busca);
        if ('' !== $busca) {
            $this->filtrarPorBusca($qb, $busca);
        }

        return $qb->getQuery()->getResult();
    }

    private function filtrarPorBusca(QueryBuilder $qb, string $busca): void
    {
        $digitos = ltrim($busca, '#');
        $condicao = 'LOWER(c.assunto) LIKE :busca OR LOWER(c.descricao) LIKE :busca';
        if ('' !== $digitos && strlen($digitos) <= 9 && ctype_digit($digitos)) {
            $condicao .= ' OR c.numero = :numero';
            $qb->setParameter('numero', (int) $digitos);
        }
        $qb->andWhere($condicao)->setParameter('busca', '%' . addcslashes(mb_strtolower($busca), '%_\\') . '%');
    }

    /** @return array<string, int> chave = valor do status */
    public function contarPorStatus(Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('c')
            ->select('c.status AS status, COUNT(c.id) AS total')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('c.status')
            ->getQuery()
            ->getArrayResult();
        $contagem = [];
        foreach ($linhas as $linha) {
            $status = $linha['status'];
            $contagem[$status instanceof StatusDoChamado ? $status->value : (string) $status] = (int) $linha['total'];
        }

        return $contagem;
    }
}
