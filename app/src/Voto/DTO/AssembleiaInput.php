<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;

/**
 * Formulário "Nova assembleia" / "Rascunho: {nome}": o título (nome do evento) e o peso do voto ("Fração ideal" ou
 * "Uma unidade, um voto"). Sem data: no modo mesa o desenho não mostra Início/Encerramento.
 */
final readonly class AssembleiaInput
{
    public function __construct(
        public string $titulo,
        public string $peso,
    ) {
    }

    public function pesoDoVoto(): PesoDoVoto
    {
        return PesoDoVoto::tryFrom($this->peso)
            ?? throw new OperacaoInvalidaNaAssembleiaException('Escolha o peso do voto.');
    }
}
