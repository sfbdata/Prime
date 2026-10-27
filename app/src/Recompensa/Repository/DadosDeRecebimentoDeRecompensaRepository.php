<?php

declare(strict_types=1);

namespace App\Recompensa\Repository;

use App\Recompensa\Entity\DadosDeRecebimentoDeRecompensa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dados de recebimento dos indicadores. Sem filtro de tenant de propósito: é dado GLOBAL da plataforma, lido e escrito só
 * pelo Master (ver {@see DadosDeRecebimentoDeRecompensa}).
 *
 * @extends ServiceEntityRepository<DadosDeRecebimentoDeRecompensa>
 */
class DadosDeRecebimentoDeRecompensaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DadosDeRecebimentoDeRecompensa::class);
    }

    public function salvar(DadosDeRecebimentoDeRecompensa $dados, bool $flush = false): void
    {
        $this->getEntityManager()->persist($dados);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function buscarDoIndicador(int $indicadorId): ?DadosDeRecebimentoDeRecompensa
    {
        return $this->findOneBy(['indicador' => $indicadorId]);
    }

    /** @return array<int, DadosDeRecebimentoDeRecompensa> por id do indicador */
    public function todosPorIndicador(): array
    {
        $porIndicador = [];
        foreach ($this->createQueryBuilder('d')->getQuery()->getResult() as $dados) {
            $porIndicador[(int) $dados->getIndicador()->getId()] = $dados;
        }

        return $porIndicador;
    }
}
