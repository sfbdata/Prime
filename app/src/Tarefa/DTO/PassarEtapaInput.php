<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * O que o modal "Concluir etapa e passar adiante" envia (desenhos 04 L131 e 04B L90-101). `destino` vazio é a
 * próxima etapa da sequência; uma etapa adiante dela salta as do meio, uma anterior volta. `prazo` em `Y-m-d`;
 * vazio, o padrão de 2 dias úteis. O responsável chega já conferido pelo controller (`ColaboradoresAtribuiveis`).
 */
final readonly class PassarEtapaInput
{
    public function __construct(
        public string $tipoDeDemanda,
        public string $destino = '',
        public ?string $prazo = null,
        public string $pendencias = '',
        public string $feito = '',
    ) {
    }
}
