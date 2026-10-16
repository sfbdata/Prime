<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;

/** A demanda, desde que quem pede possa convidar nela, e os convites dela por id. */
final class GuardaDeConvites
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
    ) {
    }

    /** @return array{0: DemandaExterna, 1: Contexto} a demanda e o contexto efetivo (Convidado, se o acesso vem do convite) */
    public function demanda(Contexto $ctx, string $codigo, bool $novoConvite = false): array
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        $pode = $novoConvite ? $this->politica->podeConvidar($ctx, $demanda) : $this->politica->podeGerirConvites($ctx, $demanda);
        if (!$pode) {
            throw new AcaoNaoPermitida('Você não pode convidar profissionais nesta demanda.');
        }

        return [$demanda, $ctx];
    }

    /** Convite de OUTRA demanda é "não encontrado", como a demanda alheia. */
    public function convite(DemandaExterna $demanda, int $id): ConviteDemanda
    {
        foreach ($demanda->getConvites() as $c) {
            if ($c->getId() === $id) {
                return $c;
            }
        }

        throw new DemandaNaoEncontrada();
    }
}
