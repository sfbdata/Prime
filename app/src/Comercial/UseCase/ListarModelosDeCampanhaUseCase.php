<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\Entity\ModeloDeCampanha;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use Psr\Clock\ClockInterface;

/**
 * A grade "1 · MODELO" (dc L.542, `Object.assign({}, MODELOS, DM, CU)`): os fixos na ordem do dc e depois os próprios
 * do Master, com as 17 datas comemorativas (DM, F4) no meio, na ordem da próxima data a partir de hoje.
 *
 * @phpstan-import-type ModeloEmJson from ModeloDaGrade
 */
final class ListarModelosDeCampanhaUseCase
{
    public function __construct(
        private readonly ModelosDeCampanha $fixos,
        private readonly ModeloDeCampanhaRepository $proprios,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return list<ModeloEmJson> */
    public function executar(): array
    {
        return [
            ...array_map(ModeloDaGrade::deFixo(...), $this->fixos->fixos()),
            ...array_map(ModeloDaGrade::deFixo(...), $this->fixos->datas($this->relogio->now())),
            ...array_map(static fn (ModeloDeCampanha $m): array => ModeloDaGrade::deEntidade($m), $this->proprios->todos()),
        ];
    }
}
