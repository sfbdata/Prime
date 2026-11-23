<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Envio\LayoutDoEmail;

/** Um modelo pronto para o Carteiro: assunto, selo e título do cabeçalho, o miolo e o layout do miolo. */
final class ModeloMontado
{
    public function __construct(
        public readonly string $assunto,
        public readonly string $selo,
        public readonly string $titulo,
        public readonly string $corpoHtml,
        public readonly LayoutDoEmail $layout = LayoutDoEmail::Padrao,
    ) {
    }
}
