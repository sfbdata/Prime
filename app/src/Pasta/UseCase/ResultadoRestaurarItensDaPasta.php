<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

/**
 * O que `RestaurarItensDaPastaUseCase` tirou da lixeira: `documentos` conta TODOS os arquivos que
 * voltaram (os selecionados e os de dentro das subpastas restauradas); `secoes`, as subpastas
 * selecionadas mais a descendência que voltou junto; `paraARaiz`, quantos itens foram devolvidos
 * à raiz porque o pai continua na lixeira (a tela avisa).
 */
final readonly class ResultadoRestaurarItensDaPasta
{
    public function __construct(
        public int $documentos,
        public int $secoes,
        public int $paraARaiz,
    ) {
    }
}
