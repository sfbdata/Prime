<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\CalendarioDasDatasComemorativas;
use App\Comercial\DTO\DataComemorativaOutput;
use App\Comercial\DTO\DatasComemorativasOutput;
use App\Comercial\Entity\DataComemorativa;
use App\Comercial\Repository\DataComemorativaRepository;
use Psr\Clock\ClockInterface;

/**
 * O bloco "Datas comemorativas" do Comercial (desenho 05 VS 21.10.26 L335-339 e L678-682; spec §13, F4): as 17 datas
 * na ordem da próxima ocorrência a partir de hoje (dc L626, `sort((a, b) => a.d - b.d)`), cada uma com o estado, e o
 * "N ativas". A data sem linha no banco vale o {@see DataComemorativa::PADRAO}.
 */
final class ListarDatasComemorativasUseCase
{
    public function __construct(
        private readonly DataComemorativaRepository $datas,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(): DatasComemorativasOutput
    {
        $hoje = $this->relogio->now();
        $estados = $this->datas->estados();
        $linhas = array_map(
            static fn (string $chave): DataComemorativaOutput => DataComemorativaOutput::deEstado($chave, DataComemorativa::estadoOuPadrao($estados, $chave), $hoje),
            CalendarioDasDatasComemorativas::chaves(),
        );
        // Estável: duas datas no mesmo dia ficam na ordem do dc.
        usort($linhas, static fn (DataComemorativaOutput $a, DataComemorativaOutput $b): int => $a->diasQueFaltam <=> $b->diasQueFaltam);

        return new DatasComemorativasOutput($linhas, \count(array_filter($linhas, static fn (DataComemorativaOutput $l): bool => $l->ligada)));
    }

    /** O que o estado guardado vira na tela (o mesmo da listagem), para a resposta da alteração. */
    public static function linha(DataComemorativa $d, \DateTimeImmutable $hoje): DataComemorativaOutput
    {
        return DataComemorativaOutput::deEstado($d->getChave(), ['ligada' => $d->isLigada(), 'antecedencia' => $d->getAntecedencia(), 'publico' => $d->getPublico()], $hoje);
    }
}
