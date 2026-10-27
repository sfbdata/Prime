<?php

declare(strict_types=1);

namespace App\Profile\Repository;

use App\Profile\Entity\ResumoDiarioEnvio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ResumoDiarioEnvio> */
class ResumoDiarioEnvioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResumoDiarioEnvio::class);
    }

    /**
     * Grava o envio do dia, se ainda não houver: true = gravou agora (pode enviar); false = já tinha (não envia de
     * novo). SQL direto com `ON CONFLICT DO NOTHING`: dois comandos ao mesmo tempo não enviam em dobro.
     */
    public function registrarSeNovo(int $tenantId, int $userId, \DateTimeImmutable $dia, \DateTimeImmutable $agora): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO resumo_diario_envio (tenant_id, user_id, dia, enviado_em) VALUES (:t, :u, :d, :e)
             ON CONFLICT (tenant_id, user_id, dia) DO NOTHING',
            ['t' => $tenantId, 'u' => $userId, 'd' => $dia->format('Y-m-d'), 'e' => $agora->format('Y-m-d H:i:s')],
        ) === 1;
    }

    /** Desfaz o registro do dia quando o e-mail não saiu: a próxima rodada tenta de novo. */
    public function desfazer(int $tenantId, int $userId, \DateTimeImmutable $dia): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM resumo_diario_envio WHERE tenant_id = :t AND user_id = :u AND dia = :d',
            ['t' => $tenantId, 'u' => $userId, 'd' => $dia->format('Y-m-d')],
        );
    }

    public function jaEnviado(int $tenantId, int $userId, \DateTimeImmutable $dia): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM resumo_diario_envio WHERE tenant_id = :t AND user_id = :u AND dia = :d',
            ['t' => $tenantId, 'u' => $userId, 'd' => $dia->format('Y-m-d')],
        );
    }
}
