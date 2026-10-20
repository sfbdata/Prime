<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * A referência do despacho como a tela a recebe (`MensagemOutput::$despacho`): o chip "Pasta N" sai daqui, não do
 * texto. Só existe para quem vê a pasta na hora da leitura. `nup` é nulo quando a pasta não tem NUP (a tela mostra o id).
 */
final class DespachoOutput
{
    public function __construct(
        public readonly int $pastaId,
        public readonly ?string $nup,
    ) {
    }
}
