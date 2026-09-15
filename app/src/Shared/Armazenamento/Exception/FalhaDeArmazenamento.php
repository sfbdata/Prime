<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento\Exception;

/**
 * O backend não conseguiu cumprir a operação — disco cheio, permissão, I/O.
 *
 * Distinta de `ArquivoNaoEncontrado` de propósito: "não consegui ler o diretório" não é
 * "o arquivo não existe". Confundir os dois é como uma limpeza apaga registro válido, e é por
 * isso que `existe()` LANÇA nesse caso em vez de devolver false.
 */
final class FalhaDeArmazenamento extends \RuntimeException
{
}
