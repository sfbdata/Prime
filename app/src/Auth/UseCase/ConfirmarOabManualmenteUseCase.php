<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Enum\StatusOab;
use App\Auth\Service\EspelhoDaOabPrincipal;
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
        private readonly EspelhoDaOabPrincipal $espelho,
    ) {
    }

    public function executar(User $alvo): void
    {
        $alvo->setOabStatus(StatusOab::Confirmada);
        $alvo->setOabVerificadaEm(new \DateTimeImmutable());
        // A fila do Master ainda trabalha por User: a inscrição principal acompanha (spec meu-perfil-oab §3).
        $this->espelho->doUsuarioParaPrincipal($alvo);

        $this->em->flush();
    }
}
