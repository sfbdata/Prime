<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Uma opção da pauta aberta na cédula do morador. Nunca leva contador: a cédula só mostra totais na pauta encerrada. A descrição e a composição da chapa aparecem na urna abaixo do nome; `temFoto` diz se há foto 3x4 (a urna a pede pela rota própria, nunca por chave ou caminho). */
final readonly class OpcaoNaCedulaOutput
{
    public function __construct(
        public int $id,
        public int $numero,
        public string $rotulo,
        public bool $abstencao,
        public ?string $descricao = null,
        public ?string $composicao = null,
        public bool $temFoto = false,
    ) {
    }
}
