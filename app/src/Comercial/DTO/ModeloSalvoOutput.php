<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * O modelo criado, copiado, importado ou salvo, com a mensagem literal do dc (L.549-553) para a tela.
 *
 * @phpstan-import-type ModeloEmJson from ModeloDaGrade
 */
final class ModeloSalvoOutput
{
    /** @param ModeloEmJson $modelo */
    public function __construct(
        public readonly array $modelo,
        public readonly string $mensagem,
    ) {
    }
}
