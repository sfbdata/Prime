<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * O resultado do "Abonar selecionadas" da aba Equipe: quantas foram abonadas e, de cada uma que não foi, o motivo
 * (uma mensagem por recusa, na ordem em que vieram).
 */
final readonly class AbonoEmLoteOutput
{
    /** @param list<string> $recusas */
    public function __construct(
        public int $abonadas,
        public array $recusas,
    ) {
    }

    /**
     * "1 justificativa abonada." ou "N justificativas abonadas."; nenhuma, null. É o português de referência: o
     * controller traduz pela chave `ponto_servidor.equipe.abonadas` (plural ICU), com `abonadas` como quantidade e,
     * em texto, como `numero` (sem separador de milhar, igual ao sprintf).
     */
    public function mensagemDasAbonadas(): ?string
    {
        if ($this->abonadas === 0) {
            return null;
        }

        return $this->abonadas === 1
            ? '1 justificativa abonada.'
            : sprintf('%d justificativas abonadas.', $this->abonadas);
    }
}
