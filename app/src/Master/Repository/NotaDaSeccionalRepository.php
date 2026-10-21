<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\NotaDaSeccional;
use App\Master\Entity\SeccionalOab;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Notas explicativas das seccionais da OAB (uma por UF). Sem filtro de tenant de propósito: tabela de
 * PLATAFORMA (ver {@see NotaDaSeccional}), lida e gravada só pelos UseCases do Master.
 *
 * @extends ServiceEntityRepository<NotaDaSeccional>
 */
class NotaDaSeccionalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotaDaSeccional::class);
    }

    public function daSeccional(SeccionalOab $seccional): ?NotaDaSeccional
    {
        return $this->findOneBy(['seccional' => $seccional]);
    }

    /**
     * A nota de cada seccional que tem uma, com a data (AAAA-MM-DD) e o nome de quem gravou por último.
     * Uma consulta só para as 27, sem carregar entidades.
     *
     * @return array<string, array{texto: string, atualizadaEm: string, autor: ?string}> UF => nota
     */
    public function todasPorUf(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT s.uf, n.texto, n.atualizada_em, u.full_name AS autor
               FROM master_nota_seccional n
               JOIN master_seccional_oab s ON s.id = n.seccional_id
          LEFT JOIN "user" u ON u.id = n.atualizada_por_id',
        );

        $porUf = [];
        foreach ($linhas as $l) {
            $porUf[(string) $l['uf']] = [
                'texto'        => (string) $l['texto'],
                'atualizadaEm' => substr((string) $l['atualizada_em'], 0, 10),
                'autor'        => $l['autor'] !== null ? (string) $l['autor'] : null,
            ];
        }

        return $porUf;
    }

    public function salvar(NotaDaSeccional $nota, bool $flush = false): void
    {
        $this->getEntityManager()->persist($nota);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(NotaDaSeccional $nota, bool $flush = false): void
    {
        $this->getEntityManager()->remove($nota);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
