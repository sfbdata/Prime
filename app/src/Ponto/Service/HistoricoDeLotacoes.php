<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * A lotação de uma pessoa NUM escritório, dia a dia (spec `docs/specs/ponto-desenho-0811.md`, E6: lotação com
 * vigência, no molde do `HistoricoDeJornadas`). Guarda só os ids das lotações: o feriado da lotação compara pelo id.
 *
 * Regras: cada versão vale da sua data até a véspera da seguinte; a mais recente vale indefinidamente; a mais antiga
 * (a inicial, sem data) vale também para trás. Sem nenhuma versão, a lotação de hoje do vínculo vale para todos os
 * dias (o vínculo que nunca trocou de lotação). Sem vínculo, nenhuma lotação.
 */
final class HistoricoDeLotacoes
{
    /**
     * @param list<array{0: ?string, 1: ?int}> $versoes [desde (Y-m-d, nulo na inicial), id da lotação], da mais antiga
     *                                                  para a mais nova
     */
    private function __construct(
        private readonly array $versoes,
        private readonly ?int $lotacaoDeHoje,
    ) {
    }

    public static function semVinculo(): self
    {
        return new self([], null);
    }

    /**
     * @param ?int                                      $lotacaoDeHoje a do vínculo (`user_tenant.lotacao_id`)
     * @param iterable<array{desde: ?string, lotacao: ?int}> $versoes  em qualquer ordem
     */
    public static function doVinculo(?int $lotacaoDeHoje, iterable $versoes): self
    {
        $lista = [];
        foreach ($versoes as $versao) {
            $lista[] = [$versao['desde'], $versao['lotacao']];
        }
        // A inicial (sem data) primeiro; as outras pela data.
        usort($lista, static fn (array $a, array $b): int => [$a[0] !== null, $a[0]] <=> [$b[0] !== null, $b[0]]);

        return new self($lista, $lotacaoDeHoje);
    }

    /** O id da lotação que valia no dia (só a data conta), ou nulo (sem lotação naquele dia). */
    public function lotacaoNoDia(\DateTimeInterface $dia): ?int
    {
        if ($this->versoes === []) {
            return $this->lotacaoDeHoje;
        }
        $data = $dia->format('Y-m-d');
        $vigente = $this->versoes[0][1];
        foreach ($this->versoes as [$desde, $lotacao]) {
            if ($desde !== null && $desde > $data) {
                break;
            }
            $vigente = $lotacao;
        }

        return $vigente;
    }
}
