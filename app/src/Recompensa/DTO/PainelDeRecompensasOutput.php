<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

/**
 * O que a seção Recompensas mostra (desenho 05 Perfis 1.2 VS 25.10.26): a regra geral, os cinco KPIs e a lista de
 * Indicadores, tudo calculado na leitura. Em F1 nada foi pago (JÁ PAGO é zero) e o saldo é o crédito.
 */
final readonly class PainelDeRecompensasOutput
{
    /** @param list<IndicadorDeRecompensaOutput> $indicadores */
    public function __construct(
        public bool $ativo,
        public int $valorCentavos,
        public int $minEscritorios,
        public int $carenciaDias,
        public int $alertaQtd,
        public bool $exigePasta,
        public array $indicadores,
    ) {
    }

    public function totalContas(): int
    {
        return array_sum(array_map(static fn (IndicadorDeRecompensaOutput $i): int => $i->contas, $this->indicadores));
    }

    public function totalLiberados(): int
    {
        return count(array_filter($this->indicadores, static fn (IndicadorDeRecompensaOutput $i): bool => $i->liberado));
    }

    public function totalAPagarCentavos(): int
    {
        return array_sum(array_map(static fn (IndicadorDeRecompensaOutput $i): int => $i->saldoCentavos, $this->indicadores));
    }

    public function totalPagoCentavos(): int
    {
        return array_sum(array_map(static fn (IndicadorDeRecompensaOutput $i): int => $i->pagoCentavos, $this->indicadores));
    }

    public function comCondicaoIndividual(): int
    {
        return count(array_filter($this->indicadores, static fn (IndicadorDeRecompensaOutput $i): bool => $i->condicao !== null));
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'regras' => [
                'ativo' => $this->ativo, 'valorCentavos' => $this->valorCentavos, 'minEscritorios' => $this->minEscritorios,
                'carenciaDias' => $this->carenciaDias, 'alertaQtd' => $this->alertaQtd, 'exigePasta' => $this->exigePasta,
                'comCondicaoIndividual' => $this->comCondicaoIndividual(),
            ],
            'kpis' => [
                'indicadores' => count($this->indicadores), 'contasAbertas' => $this->totalContas(), 'liberados' => $this->totalLiberados(),
                'aPagarCentavos' => $this->totalAPagarCentavos(), 'jaPagoCentavos' => $this->totalPagoCentavos(),
            ],
            'indicadores' => array_map(static fn (IndicadorDeRecompensaOutput $i): array => $i->paraArray(), $this->indicadores),
        ];
    }
}
