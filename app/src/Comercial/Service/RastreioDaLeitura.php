<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Legal\PoliticaPrivacidadeVigente;

/**
 * Se a confirmação de leitura de uma campanha vale de fato (F3g, desenho 05 VS 21.10.26; spec comercial-e-emails §10,
 * decisão 4): a escolha da campanha E as duas chaves globais do rastreio, `COMERCIAL_RASTREIO_LIGADO` e a Política de
 * Privacidade citar o rastreio ({@see PoliticaPrivacidadeVigente::CITA_RASTREIO_DE_EMAIL}), as mesmas do
 * {@see MontadorDeEmail}. Enquanto alguma estiver desligada, a chave da tela aparece desligada, com a explicação, e o
 * relatório mostra "não rastreada".
 */
final class RastreioDaLeitura
{
    public function __construct(
        private readonly bool $rastreioLigado,
        private readonly bool $politicaCitaRastreio = PoliticaPrivacidadeVigente::CITA_RASTREIO_DE_EMAIL,
    ) {
    }

    /** As duas chaves globais: a tela deixa ligar a confirmação de leitura. */
    public function disponivel(): bool
    {
        return $this->rastreioLigado && $this->politicaCitaRastreio;
    }

    /** A leitura desta campanha é rastreada de fato. */
    public function efetivo(bool $escolhaDaCampanha): bool
    {
        return $escolhaDaCampanha && $this->disponivel();
    }
}
