<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeConvites;
use App\TrabalhoExterno\Service\TrabalhoExternoMailer;

/**
 * "Reenviar convite". O banco só tem o hash do token, então reenviar gera um link NOVO: o antigo
 * deixa de valer. Até 3 reenvios; convite revogado não se reenvia.
 */
final class ReenviarConviteUseCase
{
    public function __construct(
        private readonly GuardaDeConvites $guarda,
        private readonly DemandaExternaRepository $repositorio,
        private readonly TrabalhoExternoMailer $mailer,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, int $id): DemandaExterna
    {
        [$demanda, $ctx] = $this->guarda->demanda($ctx, $codigo);
        $convite = $this->guarda->convite($demanda, $id);
        $token = bin2hex(random_bytes(32));
        $convite->reenviar(ConviteDemanda::hashDoToken($token));
        $this->repositorio->salvar($demanda, flush: true);

        $this->mailer->enviarConvite($convite, $token);

        return $demanda;
    }
}
