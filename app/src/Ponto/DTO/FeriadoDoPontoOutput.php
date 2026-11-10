<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\Feriado;

/**
 * Uma linha da lista "Feriados do escritório" (desenho 16, L258; spec `docs/specs/ponto-desenho-0811.md`, E6): a data
 * (dd/mm no que repete todo ano, cujo ano não conta; dd/mm/aaaa no pontual, como na página "Feriados e Folgas"), o
 * nome, a abrangência ("Todas as sedes" ou o nome da lotação) e a marca do que repete.
 */
final class FeriadoDoPontoOutput
{
    public const TODAS_AS_SEDES = 'Todas as sedes';

    public function __construct(
        public readonly int $id,
        public readonly string $data,
        public readonly string $nome,
        public readonly string $abrangencia,
        public readonly bool $recorrente,
    ) {
    }

    public static function doFeriado(Feriado $feriado): self
    {
        $recorrente = (bool) $feriado->isRecorrente();

        return new self(
            (int) $feriado->getId(),
            (string) $feriado->getData()?->format($recorrente ? 'd/m' : 'd/m/Y'),
            (string) $feriado->getNome(),
            $feriado->getLotacao()?->getNome() ?? self::TODAS_AS_SEDES,
            $recorrente,
        );
    }
}
