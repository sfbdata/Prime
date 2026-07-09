<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * O arquivo enviado excede o limite de tamanho permitido para o seu tipo. Erro de entrada,
 * traduzido pelo controller.
 */
final class ArquivoMuitoGrandeException extends \DomainException
{
    public function __construct(string $nomeArquivo, int $limiteBytes)
    {
        parent::__construct(sprintf(
            '"%s": excede o limite de %d MB.',
            $nomeArquivo,
            intdiv($limiteBytes, 1024 * 1024),
        ));
    }
}
