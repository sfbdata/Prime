<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Uma pauta apurada no resumo para divulgar: só texto e contagens públicas. Nunca nome, unidade, e-mail nem quem votou.
 */
final readonly class PautaNoResumo
{
    /** @param list<array{rotulo: string, votos: int}> $linhas as opções por peso, depois abstenções ou brancos e nulos */
    public function __construct(
        public string $titulo,
        public string $participacao,
        public string $apuradaEm,
        public string $quorum,
        public string $resultado,
        public array $linhas,
    ) {
    }

    /** @return array{titulo: string, participacao: string, apuradaEm: string, quorum: string, resultado: string, linhas: list<array{rotulo: string, votos: int}>} */
    public function paraArray(): array
    {
        return [
            'titulo' => $this->titulo,
            'participacao' => $this->participacao,
            'apuradaEm' => $this->apuradaEm,
            'quorum' => $this->quorum,
            'resultado' => $this->resultado,
            'linhas' => $this->linhas,
        ];
    }
}
