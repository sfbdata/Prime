<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

/**
 * Interna do carimbo: a recusa com o motivo do protótipo (bj-assina-pdf.js:23), sem o " Use o original." que o
 * `CarimboVisivel` acrescenta ao virar resultado. Nunca sai do `CarimboVisivel`.
 */
final class CarimboVisivelRecusa extends \RuntimeException
{
}
