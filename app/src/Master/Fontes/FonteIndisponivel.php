<?php

declare(strict_types=1);

namespace App\Master\Fontes;

/**
 * A fonte pública (IBGE ou OAB) não respondeu ou respondeu algo que não dá para ler.
 * A mensagem é para log; a tela usa o texto do desenho.
 */
final class FonteIndisponivel extends \RuntimeException
{
}
