<?php

declare(strict_types=1);

namespace App\Comercial\Service;

/** Um modelo pronto para o Carteiro: assunto, selo e título do cabeçalho, e o miolo. */
final class ModeloMontado
{
    public function __construct(
        public readonly string $assunto,
        public readonly string $selo,
        public readonly string $titulo,
        public readonly string $corpoHtml,
    ) {
    }
}
