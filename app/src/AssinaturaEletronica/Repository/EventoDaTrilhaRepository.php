<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Repository;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\EventoDaTrilha;
use App\AssinaturaEletronica\Entity\Signatario;
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
     * Quantos eventos do tipo o signatário tem desde a data, e quando foi o mais antigo deles: o teto diário do código
     * conta pela trilha, sem tabela nova (contrato da etapa 2, seção 3).
     *
     * @return array{0: int, 1: ?\DateTimeImmutable}
     */
    public function contarDoSignatarioDesde(Signatario $signatario, TipoDeEvento $tipo, \DateTimeImmutable $desde): array
    {
        /** @var array{total: int|string, primeiro: ?string} $linha */
        $linha = $this->createQueryBuilder('e')
            ->select('COUNT(e.id) AS total', 'MIN(e.criadoEm) AS primeiro')
            ->andWhere('e.documento = :documento')
            ->andWhere('e.signatarioRef = :signatario')
            ->andWhere('e.tipo = :tipo')
            ->andWhere('e.criadoEm >= :desde')
            ->setParameter('documento', $signatario->getDocumento())
            ->setParameter('signatario', $signatario->getId())
            ->setParameter('tipo', $tipo)
            ->setParameter('desde', $desde)
            ->getQuery()
            ->getSingleResult();

        return [(int) $linha['total'], $linha['primeiro'] !== null ? new \DateTimeImmutable((string) $linha['primeiro']) : null];
    }

    /** Se o signatário já tem um evento do tipo (o "abriu o link" grava uma vez só). */
    public function existeDoSignatario(Signatario $signatario, TipoDeEvento $tipo): bool
    {
        return $this->createQueryBuilder('e')
            ->select('e.id')
            ->andWhere('e.documento = :documento')
            ->andWhere('e.signatarioRef = :signatario')
            ->andWhere('e.tipo = :tipo')
            ->setParameter('documento', $signatario->getDocumento())
            ->setParameter('signatario', $signatario->getId())
            ->setParameter('tipo', $tipo)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /** Quando foi o último evento do tipo no documento; nulo se nunca houve. */
    public function ultimoDoDocumento(DocumentoParaAssinar $documento, TipoDeEvento $tipo): ?\DateTimeImmutable
    {
        $linha = $this->createQueryBuilder('e')
            ->select('e.criadoEm')
            ->andWhere('e.documento = :documento')
            ->andWhere('e.tipo = :tipo')
            ->setParameter('documento', $documento)
            ->setParameter('tipo', $tipo)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return \is_array($linha) && $linha['criadoEm'] instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($linha['criadoEm']) : null;
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
}
