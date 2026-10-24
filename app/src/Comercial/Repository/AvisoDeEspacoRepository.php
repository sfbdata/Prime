<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\AvisoDeEspaco;
use App\Comercial\Enum\Automacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AvisoDeEspaco>
 */
class AvisoDeEspacoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvisoDeEspaco::class);
    }

    /**
     * Reserva a vez do aviso deste escritório no dia (spec §4, N5). O `plano` vazio do `e90` e do `e100` colide de
     * verdade no UNIQUE (não é NULL). Devolve false se a vez já foi usada: o aviso não sai de novo.
     */
    public function reservar(int $tenantId, Automacao $chave, \DateTimeImmutable $dia, string $plano, \DateTimeImmutable $agora): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO comercial_aviso_espaco (tenant_id, chave, dia, plano, criado_em)
             VALUES (:t, :c, :d, :p, :agora)
             ON CONFLICT (tenant_id, chave, dia, plano) DO NOTHING',
            ['t' => $tenantId, 'c' => $chave->value, 'd' => $dia->format('Y-m-d'), 'p' => $plano, 'agora' => $agora->format('Y-m-d H:i:s')],
        ) === 1;
    }
}
