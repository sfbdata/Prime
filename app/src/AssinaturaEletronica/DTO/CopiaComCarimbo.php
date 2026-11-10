<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

/**
 * A "Cópia com carimbo visível" pronta para a resposta: o nome do download ("{nome do documento} - com carimbo.pdf",
 * dc:384) e os bytes carimbados, ou o motivo da recusa do {@see \App\AssinaturaEletronica\Service\CarimboVisivel}, sem
 * o " Use o original." que a tela acrescenta no aviso vermelho (dc:384). Sempre um dos dois.
 */
final readonly class CopiaComCarimbo
{
    public function __construct(
        public string $nome,
        public ?string $pdf,
        public ?string $recusa,
    ) {
    }
}
