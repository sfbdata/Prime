<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use App\Agenda\Entity\PushEnviado;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PushEnviado> */
class PushEnviadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushEnviado::class);
    }

    /**
     * Reserva o envio de um lembrete para a pessoa. Só quem consegue inserir envia: duas varreduras ao
     * mesmo tempo (dois workers, cadeia duplicada) não mandam o mesmo aviso duas vezes. O índice único
     * (usuario_id, chave) garante no banco.
     */
    public function reservar(Tenant $tenant, User $usuario, string $chave, \DateTimeImmutable $agora): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO agenda_push_enviado (tenant_id, usuario_id, chave, enviado_em) VALUES (:t, :u, :c, :agora) ON CONFLICT (usuario_id, chave) DO NOTHING',
            ['t' => $tenant->getId(), 'u' => $usuario->getId(), 'c' => mb_substr($chave, 0, 120), 'agora' => $agora->format('Y-m-d H:i:s')],
        );
    }

    /** Reservas com mais de 7 dias não servem mais (o cartão só aparece até 2 h depois do início). */
    public function limparAntigos(\DateTimeImmutable $agora): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM agenda_push_enviado WHERE enviado_em < :limite',
            ['limite' => $agora->modify('-7 days')->format('Y-m-d H:i:s')],
        );
    }
}
