<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\DTO\ResumoDeEventosDeDesempenho;
use App\Master\Entity\EventoDeDesempenho;
use App\Master\Enum\TipoDeEventoDeDesempenho;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Eventos do monitor de saúde. Sem filtro de tenant de propósito: telemetria GLOBAL da plataforma
 * (ver {@see EventoDeDesempenho}).
 *
 * @extends ServiceEntityRepository<EventoDeDesempenho>
 */
class EventoDeDesempenhoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventoDeDesempenho::class);
    }

    public function salvar(EventoDeDesempenho $evento, bool $flush = false): void
    {
        $this->getEntityManager()->persist($evento);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Contagens por tipo desde `$desde`, numa consulta só; o P95 das cargas vem de outra (percentil no banco). */
    public function resumoDesde(\DateTimeImmutable $desde): ResumoDeEventosDeDesempenho
    {
        $linhas = $this->createQueryBuilder('e')
            ->select('e.tipo AS tipo', 'COUNT(e.id) AS total', 'SUM(CASE WHEN e.ok = false THEN 1 ELSE 0 END) AS falhos')
            ->where('e.em >= :desde')
            ->setParameter('desde', $desde)
            ->groupBy('e.tipo')
            ->getQuery()
            ->getArrayResult();

        $porTipo = [];
        foreach ($linhas as $linha) {
            $tipo = $linha['tipo'] instanceof TipoDeEventoDeDesempenho ? $linha['tipo']->value : (string) $linha['tipo'];
            $porTipo[$tipo] = ['total' => (int) $linha['total'], 'falhos' => (int) $linha['falhos']];
        }
        $n = static fn (string $tipo, string $campo): int => $porTipo[$tipo][$campo] ?? 0;

        return new ResumoDeEventosDeDesempenho(
            pings: $n('ping', 'total'),
            pingsOk: $n('ping', 'total') - $n('ping', 'falhos'),
            cargasMedidas: $n('carga', 'total'),
            p95Ms: $this->p95DasCargasDesde($desde),
            erros: $n('erro', 'total'),
            logins: $n('login', 'total'),
            loginsFalhos: $n('login', 'falhos'),
            uploads: $n('upload', 'total'),
            uploadsFalhos: $n('upload', 'falhos'),
        );
    }

    private function p95DasCargasDesde(\DateTimeImmutable $desde): ?int
    {
        $p95 = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT percentile_disc(0.95) WITHIN GROUP (ORDER BY ms) FROM desempenho_evento WHERE tipo = :tipo AND ms IS NOT NULL AND em >= :desde',
            ['tipo' => TipoDeEventoDeDesempenho::Carga->value, 'desde' => $desde->format('Y-m-d H:i:s')],
        );

        return $p95 === false || $p95 === null ? null : (int) $p95;
    }

    /** @return list<EventoDeDesempenho> */
    public function errosRecentesDesde(\DateTimeImmutable $desde, int $limite): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.tipo = :tipo')
            ->andWhere('e.em >= :desde')
            ->setParameter('tipo', TipoDeEventoDeDesempenho::Erro)
            ->setParameter('desde', $desde)
            ->orderBy('e.em', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /** "Limpar registros" e purga de retenção (spec §2.1): apaga eventos anteriores a `$antes`; sem data, todos. */
    public function apagarAntesDe(?\DateTimeImmutable $antes = null): int
    {
        $qb = $this->createQueryBuilder('e')->delete();
        if ($antes !== null) {
            $qb->where('e.em < :antes')->setParameter('antes', $antes);
        }

        return (int) $qb->getQuery()->execute();
    }
}
