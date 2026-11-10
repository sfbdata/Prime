<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * O quadro "RESUMO DO MÊS" da folha em PDF (desenho 16 L687 e L688): os totais do mês e o banco de horas, no texto que
 * o PDF de hoje já imprime ({@see \App\Ponto\Service\DadosDaFolhaExportada}), mais a linha nova "Dias incompletos · a
 * revisar".
 */
final readonly class ResumoDoMesDaFolha
{
    public function __construct(
        public string $trabalhadas,
        public string $extras,
        public string $saldoAnterior,
        public string $saldoAtual,
        public string $horasACompensar,
        public int $diasIncompletos,
        public int $diasARevisar,
    ) {
    }

    /**
     * Os números da folha exportada e as contagens das linhas impressas.
     *
     * @param array<string, mixed>       $dados  o array de {@see \App\Ponto\Service\DadosDaFolhaExportada::montar()}
     * @param list<LinhaDaFolhaImpressa> $linhas
     */
    public static function daFolhaExportada(array $dados, array $linhas): self
    {
        $incompletos = count(array_filter($linhas, static fn (LinhaDaFolhaImpressa $linha): bool => $linha->incompleto));
        $aRevisar = count(array_filter($linhas, static fn (LinhaDaFolhaImpressa $linha): bool => $linha->foraDeSequencia));

        return new self(
            (string) $dados['totalHorasTrabalhadas'],
            (string) $dados['totalHorasExtras'],
            (string) $dados['saldoBancoAnterior'],
            (string) $dados['saldoBancoAtual'],
            (string) $dados['horasACompensar'],
            $incompletos,
            $aRevisar,
        );
    }

    /** "N · N" da linha "Dias incompletos · a revisar:" (L688). */
    public function textoDosIncompletos(): string
    {
        return sprintf('%d · %d', $this->diasIncompletos, $this->diasARevisar);
    }
}
