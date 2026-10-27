<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\DivulgacaoNaSeccional;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Divulgações do BlueJus nas seccionais da OAB. Sem filtro de tenant de propósito: tabela de
 * PLATAFORMA (ver {@see DivulgacaoNaSeccional}), lida e gravada só pelos UseCases do Master.
 *
 * @extends ServiceEntityRepository<DivulgacaoNaSeccional>
 *
 * OBSOLETO desde a Version20261020149500 (desenho 05 inteiro): a divulgação virou a marca da UF na própria
 * SeccionalOab (status, cor e data). Nada mais lê nem grava aqui; a tabela `master_divulgacao_seccional` fica no
 * banco até a limpeza combinada com o dono (o DROP vai nessa limpeza, não agora).
 */
class DivulgacaoNaSeccionalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DivulgacaoNaSeccional::class);
    }

    /**
     * As `$limite` divulgações mais recentes de cada seccional (data, depois a ordem de registro), com o nome
     * do autor. Uma consulta só para as 27 (ROW_NUMBER por seccional), sem carregar entidades.
     *
     * @return array<string, list<array{tipo: string, data: string, nota: ?string, autor: ?string}>> UF => lista
     */
    public function ultimasPorUf(int $limite): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT uf, tipo, data, nota, autor FROM (
                SELECT s.uf, d.tipo, d.data, d.nota, u.full_name AS autor,
                       ROW_NUMBER() OVER (PARTITION BY d.seccional_id ORDER BY d.data DESC, d.id DESC) AS ordem
                  FROM master_divulgacao_seccional d
                  JOIN master_seccional_oab s ON s.id = d.seccional_id
             LEFT JOIN "user" u ON u.id = d.autor_id
             ) x
             WHERE ordem <= :limite
          ORDER BY uf, ordem',
            ['limite' => $limite],
            ['limite' => ParameterType::INTEGER],
        );

        $porUf = [];
        foreach ($linhas as $l) {
            $porUf[(string) $l['uf']][] = [
                'tipo'  => (string) $l['tipo'],
                'data'  => (string) $l['data'],
                'nota'  => $l['nota'] !== null ? (string) $l['nota'] : null,
                'autor' => $l['autor'] !== null ? (string) $l['autor'] : null,
            ];
        }

        return $porUf;
    }

    public function salvar(DivulgacaoNaSeccional $divulgacao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($divulgacao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
