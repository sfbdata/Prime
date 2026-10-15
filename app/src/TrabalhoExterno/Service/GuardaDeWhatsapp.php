<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\AvisoWhatsapp;
use App\TrabalhoExterno\Entity\ContatoWhatsapp;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;

/**
 * A demanda pedida, desde que quem pede possa usar a aba WhatsApp, e os contatos/avisos dela
 * por id. Contato ou aviso de OUTRA demanda é "não encontrado", como a demanda alheia.
 */
final class GuardaDeWhatsapp
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
    ) {
    }

    /** @return array{0: DemandaExterna, 1: Contexto} a demanda e o contexto efetivo (Convidado, se o acesso vem do convite) */
    public function demanda(Contexto $ctx, string $codigo): array
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podeUsarWhatsapp($ctx, $demanda)) {
            throw new AcaoNaoPermitida('Você não pode usar o WhatsApp desta demanda.');
        }

        return [$demanda, $ctx];
    }

    public function contato(DemandaExterna $demanda, int $id): ContatoWhatsapp
    {
        foreach ($demanda->getContatosWhatsapp() as $c) {
            if ($c->getId() === $id) {
                return $c;
            }
        }

        throw new DemandaNaoEncontrada();
    }

    public function aviso(DemandaExterna $demanda, int $id): AvisoWhatsapp
    {
        foreach ($demanda->getAvisosWhatsapp() as $a) {
            if ($a->getId() === $id) {
                return $a;
            }
        }

        throw new DemandaNaoEncontrada();
    }
}
