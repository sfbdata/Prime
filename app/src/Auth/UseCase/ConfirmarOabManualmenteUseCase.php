<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Enum\StatusOab;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Override manual do super-admin: confirma a OAB de um usuário (identidade global). Enquanto a
 * verificação automática está dormente, é o único caminho para uma OAB virar `confirmada` além do
 * backfill de dono.
 *
 * A mudança é auditada automaticamente: `User` é `Auditavel`, então o `AuditLogSubscriber` grava a
 * alteração de `oabStatus` (com ator, rota e changeset) no flush. Sem auditoria manual.
 */
final class ConfirmarOabManualmenteUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(User $alvo): void
    {
        $alvo->setOabStatus(StatusOab::Confirmada);
        $alvo->setOabVerificadaEm(new \DateTimeImmutable());

        $this->em->flush();
    }
}
