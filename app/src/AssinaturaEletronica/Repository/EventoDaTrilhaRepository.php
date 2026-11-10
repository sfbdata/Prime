<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Repository;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A trilha só cresce. Quem lê já tem o documento (filtrado pelo escritório): o evento não leva `tenant_id`.
 *
 * @extends ServiceEntityRepository<EventoDaTrilha>
 */
class EventoDaTrilhaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventoDaTrilha::class);
    }

    /**
     * Trava a linha do documento (`SELECT ... FOR UPDATE`) e devolve o hash do último evento: dois acréscimos ao mesmo
     * tempo esperam um pelo outro e a corrente não bifurca. Exige transação aberta.
     */
    public function ultimoHashTravando(DocumentoParaAssinar $documento): ?string
    {
        $this->getEntityManager()->lock($documento, LockMode::PESSIMISTIC_WRITE);

        $hash = $this->createQueryBuilder('e')
            ->select('e.hash')
            ->andWhere('e.documento = :documento')
            ->setParameter('documento', $documento)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return \is_array($hash) ? (string) $hash['hash'] : null;
    }

    /** Grava na hora: o próximo acréscimo da mesma transação precisa enxergar este hash. */
    public function salvar(EventoDaTrilha $evento): void
    {
        $em = $this->getEntityManager();
        $em->persist($evento);
        $em->flush();
    }

    /**
     * Do mais recente para o mais antigo.
     *
     * @return list<EventoDaTrilha>
     */
    public function doDocumento(DocumentoParaAssinar $documento): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.documento = :documento')
            ->setParameter('documento', $documento)
            ->orderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Função provisória "assinado em papel": dos ids dados (já filtrados pelo escritório), os que têm o evento.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function idsAssinadosEmPapel(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<array{documento: int|string}> $linhas */
        $linhas = $this->createQueryBuilder('e')
            ->select('DISTINCT IDENTITY(e.documento) AS documento')
            ->andWhere('e.tipo = :tipo')
            ->andWhere('IDENTITY(e.documento) IN (:ids)')
            ->setParameter('tipo', TipoDeEvento::AssinadoEmPapel)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $l): int => (int) $l['documento'], $linhas);
    }
}
