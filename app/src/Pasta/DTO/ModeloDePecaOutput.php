<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\ModeloDePeca;

/** O modelo aberto (visualização e editor do banco de modelos), em forma de JSON. */
final readonly class ModeloDePecaOutput
{
    public function __construct(
        public int $id,
        public string $titulo,
        public string $materia,
        public string $materiaRotulo,
        public string $tipo,
        public string $tipoRotulo,
        public string $corpo,
        public int $usos,
    ) {
    }

    public static function fromEntity(ModeloDePeca $modelo): self
    {
        return new self(
            (int) $modelo->getId(),
            $modelo->getTitulo(),
            $modelo->getMateria()->value,
            $modelo->getMateria()->rotulo(),
            $modelo->getTipo()->value,
            $modelo->getTipo()->rotulo(),
            $modelo->getCorpo(),
            $modelo->getUsos(),
        );
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
