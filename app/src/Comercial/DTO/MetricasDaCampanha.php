<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * Os números de uma campanha no Relatório (desenho 05 VS 21.10.26, L714 e L710): enviados, entregues (a regra está
 * em {@see \App\Comercial\Repository\RelatorioDeCampanhasRepository::CONDICAO_ENTREGUE}), abertos e lidos, não lidos e
 * rejeitados, somando todas as execuções. Os percentuais são sobre os enviados, como o dc (`pc` sobre o total enviado).
 *
 * O ícone segue o dc: ✓ cinza (enviado), ✓ verde (entregue) e ✓✓ verde com o % (aberto e lido).
 */
final class MetricasDaCampanha
{
    public const ENVIADO = 'enviado';
    public const ENTREGUE = 'entregue';
    public const LIDO = 'lido';
    public const LEGENDA = '✓ cinza: enviado · ✓ verde: recebido · ✓✓ verde: aberto e lido';

    public function __construct(
        public readonly int $destinatarios,
        public readonly int $naFila,
        public readonly int $enviados,
        public readonly int $entregues,
        public readonly int $lidos,
        public readonly int $rejeitados,
        public readonly int $clicaram,
    ) {
    }

    /** @param array{destinatarios: int, na_fila: int, enviados: int, entregues: int, lidos: int, rejeitados: int, clicaram: int}|null $m */
    public static function deArray(?array $m, int $destinatariosSemExecucao = 0): self
    {
        if ($m === null) {
            return new self($destinatariosSemExecucao, $destinatariosSemExecucao, 0, 0, 0, 0, 0);
        }

        return new self($m['destinatarios'], $m['na_fila'], $m['enviados'], $m['entregues'], $m['lidos'], $m['rejeitados'], $m['clicaram']);
    }

    public function naoLidos(): int
    {
        return max(0, $this->entregues - $this->lidos);
    }

    /** O percentual sobre os enviados, inteiro (o dc arredonda); "0%" sem envio. */
    public function percentual(int $n): string
    {
        return $this->enviados > 0 ? (string) (int) round($n / $this->enviados * 100) . '%' : '0%';
    }

    /** A taxa de leitura do KPI. Sem envio fica "0%" (o dc mostra um traço, que a regra do texto sem travessão tira). */
    public function taxaDeLeitura(): string
    {
        return $this->percentual($this->lidos);
    }

    public function leitura(): string
    {
        return match (true) {
            $this->lidos > 0 => self::LIDO,
            $this->entregues > 0 => self::ENTREGUE,
            default => self::ENVIADO,
        };
    }

    /** O tooltip da linha (dc L714): uma linha por número, e a legenda dos ícones depois de uma linha vazia. */
    public function dica(): string
    {
        $linhas = [
            'Enviados: ' . $this->enviados,
            'Entregues (recebidos): ' . $this->entregues . ' (' . $this->percentual($this->entregues) . ')',
            'Abertos e lidos: ' . $this->lidos . ' (' . $this->percentual($this->lidos) . ')',
            'Não lidos: ' . $this->naoLidos(),
        ];
        if ($this->rejeitados > 0) {
            $linhas[] = 'Rejeitados: ' . $this->rejeitados;
        }

        return implode("\n", $linhas) . "\n\n" . self::LEGENDA;
    }

    /**
     * @return array{destinatarios: int, naFila: int, enviados: int, entregues: int, lidos: int, naoLidos: int, rejeitados: int, clicaram: int, leitura: string, percentual: string, dica: string}
     */
    public function paraArray(): array
    {
        return [
            'destinatarios' => $this->destinatarios, 'naFila' => $this->naFila, 'enviados' => $this->enviados,
            'entregues' => $this->entregues, 'lidos' => $this->lidos, 'naoLidos' => $this->naoLidos(),
            'rejeitados' => $this->rejeitados, 'clicaram' => $this->clicaram, 'leitura' => $this->leitura(),
            'percentual' => $this->leitura() === self::LIDO ? $this->percentual($this->lidos) : '', 'dica' => $this->dica(),
        ];
    }
}
