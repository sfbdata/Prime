<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Ponto\Entity\PontoAjuste;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O repositório da entidade `PontoAjuste` (E10). De propósito sem consulta: a linhagem dos ajustes é lida junto com as
 * batidas, pelo {@see RegistroPontoRepository} (alvo do ajuste, cabeça, desconsideradas, inclusão do esquecimento),
 * com o escritório escrito em cada consulta. Quem grava é a {@see \App\Ponto\Service\GravacaoDoAjusteDoPonto}.
 *
 * @extends ServiceEntityRepository<PontoAjuste>
 */
class PontoAjusteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PontoAjuste::class);
    }
}
