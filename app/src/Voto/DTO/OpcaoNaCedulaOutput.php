<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Uma opção da pauta aberta na cédula do morador. Nunca leva contador: a cédula só mostra totais na pauta encerrada. A descrição e a composição da chapa aparecem na urna abaixo do nome. */
final readonly class OpcaoNaCedulaOutput
{
    public function __construct(
        public int $id,
        public int $numero,
        public string $rotulo,
        public bool $abstencao,
        public ?string $descricao = null,
        public ?string $composicao = null,
    ) {
    }
}
