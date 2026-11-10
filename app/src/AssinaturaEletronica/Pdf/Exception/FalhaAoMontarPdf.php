<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf\Exception;

/**
 * O PDF não pôde ser montado: fonte ausente, GD sem memória ou página do PDF enviado que o Ghostscript não leu.
 * Falha técnica (não é recusa do usuário): quem chama registra e tenta de novo depois.
 */
final class FalhaAoMontarPdf extends \RuntimeException
{
}
