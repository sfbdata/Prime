<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * O que a pergunta ao BlueJus IA devolve: só ids e o estado da análise (nunca o texto). `emAndamento` é true quando
 * já havia uma pergunta sendo respondida e o pedido devolveu essa, sem gravar mensagem nova nem gastar cota.
 */
final readonly class PerguntaDaIaOutput
{
    public function __construct(
        public int $analiseId,
        public int $conversaId,
        public int $mensagemId,
        public string $status,
        public bool $emAndamento,
    ) {
    }
}
