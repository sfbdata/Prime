<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

use App\TrabalhoExterno\Entity\Curriculo;

/** O currículo salvo e o toast do desenho para a resposta de "Salvar currículo" (l.1006). */
final readonly class ResultadoDoSalvarCurriculo
{
    /** @param array{titulo: string, texto: string} $toast */
    public function __construct(
        public Curriculo $curriculo,
        public array $toast,
    ) {
    }
}
