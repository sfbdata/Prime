<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\RegistrarEventoDeDesempenhoInput;
use App\Master\Entity\EventoDeDesempenho;
use App\Master\Repository\EventoDeDesempenhoRepository;
use Psr\Clock\ClockInterface;

/**
 * Grava um evento do monitor de saúde (spec docs/specs/master-desempenho.md, D2). O corte dos textos nos
 * tamanhos da coluna é da entidade ({@see EventoDeDesempenho::descrever()}).
 */
final class RegistrarEventoDeDesempenhoUseCase
{
    public function __construct(
        private readonly EventoDeDesempenhoRepository $eventos,
        private readonly ClockInterface $clock,
    ) {
    }

    public function executar(RegistrarEventoDeDesempenhoInput $input): void
    {
        $evento = new EventoDeDesempenho(
            $input->tipo,
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $input->ok,
            $input->ms,
        );
        $evento->descrever($input->mensagem, $input->origem, $input->pagina, $input->usuario);

        $this->eventos->salvar($evento, flush: true);
    }
}
