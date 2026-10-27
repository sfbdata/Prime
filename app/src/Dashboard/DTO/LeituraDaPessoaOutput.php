<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * Uma frase da leitura individual com a etiqueta do desenho (dc 01 L1136-1142): `observado` (fato lido
 * dos números), `correlacao` (dois números reais que andam juntos, sem afirmar causa) ou `hipotese`
 * (o que os números sugerem, a conferir). Todas saem de regra fixa, nunca de modelo de linguagem.
 */
final readonly class LeituraDaPessoaOutput
{
    public const OBSERVADO  = 'observado';
    public const CORRELACAO = 'correlacao';
    public const HIPOTESE   = 'hipotese';

    public function __construct(
        public string $tipo,
        public string $texto,
    ) {
    }
}
