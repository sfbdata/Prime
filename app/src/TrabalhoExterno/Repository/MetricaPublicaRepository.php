<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\TrabalhoExterno\Entity\LinkDeVagas;
use App\TrabalhoExterno\Entity\MetricaPublica;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os contadores anônimos da página pública (spec VS 18.10.26 §4.9). Não é `TenantAware`: não há
 * escritório nem pessoa, só dia, link e dois números. Só o painel do Master lê, e só a soma.
 *
 * @extends ServiceEntityRepository<MetricaPublica>
 */
class MetricaPublicaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetricaPublica::class);
    }

    /**
     * Soma ao registro do dia e do link num único comando (`INSERT … ON CONFLICT DO UPDATE`): duas
     * visitas ao mesmo tempo não se perdem nem duplicam a linha. Grava na hora, sem o flush do ORM.
     */
    public function somar(\DateTimeImmutable $dia, ?LinkDeVagas $link, int $visitas, int $cliques): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO trabalho_externo_metrica_publica (dia, link_id, chave_do_link, visitas, cliques)
             VALUES (:dia, :link, :chave, :visitas, :cliques)
             ON CONFLICT (dia, chave_do_link) DO UPDATE
             SET visitas = trabalho_externo_metrica_publica.visitas + EXCLUDED.visitas,
                 cliques = trabalho_externo_metrica_publica.cliques + EXCLUDED.cliques',
            [
                'dia' => $dia->format('Y-m-d'),
                'link' => $link?->getId(),
                'chave' => (int) $link?->getId(),
                'visitas' => $visitas,
                'cliques' => $cliques,
            ],
        );
    }

    /** @return array{visitas: int, cliques: int} os totais de sempre, de todos os links e sem link */
    public function totais(): array
    {
        $linha = $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(m.visitas), 0) AS visitas', 'COALESCE(SUM(m.cliques), 0) AS cliques')
            ->getQuery()
            ->getSingleResult();

        return ['visitas' => (int) $linha['visitas'], 'cliques' => (int) $linha['cliques']];
    }
}
