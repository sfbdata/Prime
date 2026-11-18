<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

/**
 * "O QUE ELE PODE FAZER" da janela Compartilhar pasta: ver a pasta e as metas é fixo; as três caixas são estas.
 * O "1 clique" do menu usa `umClique()`: movimentar e enviar documentos, sem baixar (desenho L.830).
 */
final readonly class PermissoesDoCompartilhamento
{
    public function __construct(
        public bool $movimentar = false,
        public bool $enviarDocumentos = false,
        public bool $baixarDocumentos = false,
    ) {
    }

    public static function umClique(): self
    {
        return new self(true, true, false);
    }
}
