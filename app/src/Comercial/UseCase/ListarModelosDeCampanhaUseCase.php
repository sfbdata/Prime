<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\Entity\ModeloDeCampanha;
use App\Comercial\Repository\ModeloDeCampanhaRepository;

/**
 * A grade "1 · MODELO" (dc L.542, `Object.assign({}, MODELOS, DM, CU)`): os fixos na ordem do dc e depois os próprios
 * do Master. As datas comemorativas (DM) entram com a fatia 4.
 *
 * @phpstan-import-type ModeloEmJson from ModeloDaGrade
 */
final class ListarModelosDeCampanhaUseCase
{
    public function __construct(
        private readonly ModelosDeCampanha $fixos,
        private readonly ModeloDeCampanhaRepository $proprios,
    ) {
    }

    /** @return list<ModeloEmJson> */
    public function executar(): array
    {
        return [
            ...array_map(ModeloDaGrade::deFixo(...), $this->fixos->fixos()),
            ...array_map(static fn (ModeloDeCampanha $m): array => ModeloDaGrade::deEntidade($m), $this->proprios->todos()),
        ];
    }
}
