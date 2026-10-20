<?php

declare(strict_types=1);

namespace App\Indicacao\Repository;

use App\Indicacao\Entity\IndicacaoPausa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As pessoas com os convites pausados pelo Master.
 *
 * @extends ServiceEntityRepository<IndicacaoPausa>
 */
class IndicacaoPausaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicacaoPausa::class);
    }

    public function doUsuario(int $userId): ?IndicacaoPausa
    {
        return $this->findOneBy(['user' => $userId]);
    }

    public function estaPausado(int $userId): bool
    {
        return $this->doUsuario($userId) !== null;
    }

    /**
     * Pausa a pessoa, idempotente sob corrida: dois Masters pausando ao mesmo tempo não colidem na chave (a segunda
     * inserção não faz nada). Por SQL na conexão, sem `flush` e sem exceção que fecharia o EntityManager.
     */
    public function pausar(int $userId, ?int $pausadoPorId, \DateTimeImmutable $em): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO indicacao_pausa (user_id, pausado_por_id, pausado_em) VALUES (?, ?, ?) ON CONFLICT (user_id) DO NOTHING',
            [$userId, $pausadoPorId, $em->format('Y-m-d H:i:s')],
        );
    }

    /** Retoma a pessoa; sem pausa, não faz nada. */
    public function retomar(int $userId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM indicacao_pausa WHERE user_id = ?', [$userId]);
    }

    public function salvar(IndicacaoPausa $pausa, bool $flush = false): void
    {
        $this->getEntityManager()->persist($pausa);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(IndicacaoPausa $pausa, bool $flush = false): void
    {
        $this->getEntityManager()->remove($pausa);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
