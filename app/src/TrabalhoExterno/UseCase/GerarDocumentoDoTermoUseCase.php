<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\HtmlDoTermoDeVoluntariado;
use App\TrabalhoExterno\Service\LocalizadorDeTermo;

/**
 * O HTML do termo gravado (prévia em iframe, Word e Imprimir/PDF, desenho 06 de 25/10, l.1131 a 1133): o MESMO
 * documento montado no servidor a partir do snapshot. Quem pode ver o termo completo (a Central que gere, ou o
 * voluntário dono); os demais recebem "não encontrado". Consulta: não grava nada.
 */
final class GerarDocumentoDoTermoUseCase
{
    public function __construct(
        private readonly LocalizadorDeTermo $localizador,
        private readonly HtmlDoTermoDeVoluntariado $html,
    ) {
    }

    public function executar(Contexto $ctx, string $codigoDoTermo): string
    {
        return $this->html->doTermo($this->localizador->localizar($ctx, $codigoDoTermo));
    }
}
