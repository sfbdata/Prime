<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * A cópia da assembleia: o id do novo rascunho, quantas unidades foram copiadas e se alguma tinha e-mail no original
 * (a cópia sai sem os e-mails e o aviso pede para reimportar a planilha).
 */
final readonly class ResultadoDaDuplicacao
{
    public function __construct(
        public int $assembleiaId,
        public int $unidadesCopiadas,
        public bool $emailsDeixados = false,
    ) {
    }
}
