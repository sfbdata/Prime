<?php

declare(strict_types=1);

namespace App\Master\Fontes;

/**
 * O arquivo enviado pelo Master não está no formato do modelo. A mensagem vai para a tela.
 */
final class ArquivoNaoReconhecido extends \RuntimeException
{
}
