<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Enum\StatusOab;
use App\Auth\Service\EspelhoDaOabPrincipal;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reverte/revoga uma confirmação de OAB feita pelo super-admin. O admin escolhe o destino:
 * `NaoVerificada` (volta à fila neutra) ou `Divergente` (sinaliza problema/irregularidade).
 *
 * A mudança é auditada automaticamente pelo `AuditLogSubscriber` (`User` é `Auditavel`).
 */
final class ReverterOabUseCase
{
    private const DESTINOS_PERMITIDOS = [StatusOab::NaoVerificada, StatusOab::Divergente];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EspelhoDaOabPrincipal $espelho,
    ) {
    }

    public function executar(User $alvo, StatusOab $destino): void
    {
        if (!in_array($destino, self::DESTINOS_PERMITIDOS, true)) {
            throw new \InvalidArgumentException('Destino inválido para reverter OAB: ' . $destino->value);
        }

        $alvo->setOabStatus($destino);
        $alvo->setOabVerificadaEm($destino === StatusOab::NaoVerificada ? null : new \DateTimeImmutable());
        // A fila do Master ainda trabalha por User: a inscrição principal acompanha (spec meu-perfil-oab §3).
        $this->espelho->doUsuarioParaPrincipal($alvo);

        $this->em->flush();
    }
}
