<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

/**
 * Interna do carimbo: a recusa com o motivo do protótipo (bj-assina-pdf.js:23), que o `CarimboVisivel` devolve como
 * está no resultado. O " Use o original." do aviso é do JS da tela (dc:384). Nunca sai do `CarimboVisivel`.
 */
final class CarimboVisivelRecusa extends \RuntimeException
{
}
