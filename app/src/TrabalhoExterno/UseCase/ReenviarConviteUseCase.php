<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Exception\DadosInvalidos;
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

        try {
            $this->mailer->enviarConvite($convite, $token);
        } catch (\RuntimeException) {
            // O banco já está decidido (o e-mail sai depois do COMMIT): a pessoa precisa saber que o
            // link não chegou, e o caminho para corrigir é reenviar (que gera um link novo).
            throw new DadosInvalidos('O e-mail do convite não saiu. Tente reenviar de novo.');
        }

        return $demanda;
    }
}
