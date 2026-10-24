<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PastaPagamento;
use App\Shared\Service\ValorEmReais;

/**
 * Uma linha do card Pagamentos, já pronta para impressão — a tela não decide
 * estado nem formata dinheiro.
 */
final readonly class PastaPagamentoLinhaOutput
{
    public const ESTADO_PAGO     = 'Pago';
    public const ESTADO_PENDENTE = 'Pendente';
    public const ESTADO_VENCIDA  = 'Vencida';
    public const ESTADO_PREVISTO = 'Previsto';

    public function __construct(
        public int $id,
        public string $descricao,
        public string $valorFormatado,
        public string $quando,
        public string $estado,
        /** `ok` (verde), `proximo` (âmbar), `urgente` (vermelho) ou `previsto` (azul, êxito e sucumbência). */
        public string $tom,
        public bool $pago,
        /** O valor como o campo de correção abre: "1300,00" (pt-BR, sem milhar). */
        public string $valorEditavel = '',
        /** @var list<CorrecaoDeValorDoPagamentoOutput> correções de valor, da mais antiga à mais recente */
        public array $correcoes = [],
        /** Percentual do previsto como se lê ("20", "12,5"); vazio sem percentual. */
        public string $percentualTexto = '',
    ) {}

    public function foiCorrigido(): bool
    {
        return $this->correcoes !== [];
    }

    /**
     * O valor de ANTES da primeira correção — o "era R$ a" do desenho
     * (dc 3462, `ajusteDe: aj[0].de`). Vazio quando nunca foi corrigido.
     */
    public function valorOriginalFormatado(): string
    {
        return $this->correcoes === []
            ? ''
            : PastaFinanceiroOutput::formatarReais($this->correcoes[0]->de);
    }

    /** Uma correção por linha, como o título do "corrigido · era" (dc 3462). */
    public function historicoDeCorrecoes(): string
    {
        return implode("\n", array_map(
            static fn (CorrecaoDeValorDoPagamentoOutput $c): string => $c->linha(),
            $this->correcoes,
        ));
    }

    /**
     * Três selos, como o desenho 1.2.3 mostra (dc 3438-3441): Pago (verde),
     * Pendente (âmbar) e Vencida (vermelho). Vencida é só APRESENTAÇÃO: o estado
     * continua derivado da data de pagamento e do vencimento (`estaVencido`),
     * nada é gravado. O selo segue sendo o botão de quitar nos três casos.
     *
     * Previsto (êxito e sucumbência ainda não recebidos, desenho 02 L.3615): selo azul e
     * "N% sobre o total contratado · condicionado ao resultado" (C8). O valor dele é o
     * calculado na leitura, que chega pronto em `$centavos`.
     *
     * @param list<CorrecaoDeValorDoPagamentoOutput> $correcoes do audit_log, já filtradas para ESTE pagamento
     * @param ?int $centavos o valor que vale agora (`BaseDoPercentual::centavosDe`); nulo = o gravado
     */
    public static function montar(PastaPagamento $pagamento, \DateTimeImmutable $hoje, array $correcoes = [], ?int $centavos = null): self
    {
        $pago  = $pagamento->estaPago();
        $valor = $centavos !== null ? ValorEmReais::deCentavos($centavos) : $pagamento->getValor();

        [$estado, $tom] = match (true) {
            $pago                          => [self::ESTADO_PAGO, 'ok'],
            $pagamento->estaPrevisto()     => [self::ESTADO_PREVISTO, 'previsto'],
            $pagamento->estaVencido($hoje) => [self::ESTADO_VENCIDA, 'urgente'],
            default                        => [self::ESTADO_PENDENTE, 'proximo'],
        };

        return new self(
            id: (int) $pagamento->getId(),
            descricao: $pagamento->getDescricao(),
            valorFormatado: PastaFinanceiroOutput::formatarReais($valor),
            quando: self::quando($pagamento, $hoje),
            estado: $estado,
            tom: $tom,
            pago: $pago,
            valorEditavel: str_replace('.', ',', $valor),
            correcoes: $correcoes,
            percentualTexto: self::percentualTexto($pagamento->getPercentual()),
        );
    }

    /** "20.00" → "20"; "12.50" → "12,5"; nulo → vazio. */
    public static function percentualTexto(?string $percentual): string
    {
        if ($percentual === null) {
            return '';
        }

        $texto = str_contains($percentual, '.') ? rtrim(rtrim($percentual, '0'), '.') : $percentual;

        return str_replace('.', ',', $texto);
    }

    /**
     * A linha de apoio diz a data e, quando ela ainda importa, a distância até
     * lá — nos termos do desenho (dc 3440-3441): "venceu … · há N dias" para o
     * vencido; "· hoje" e "· em N dia(s)" até 7 dias; além disso, só a data.
     * Pagamento quitado não fala mais de vencimento: fala de quando entrou.
     *
     * Parcela de parcelamento com juros leva, em qualquer estado, " · juros N% a.m."
     * (dc 3442, FPN-009) — a taxa gravada, sem zeros à direita, com vírgula.
     */
    private static function quando(PastaPagamento $pagamento, \DateTimeImmutable $hoje): string
    {
        return self::quandoSemJuros($pagamento, $hoje) . self::sufixoDeJuros($pagamento->getJurosMensal());
    }

    /** " · juros 1,5% a.m."; vazio sem taxa (lançamento antigo, entrada, avulso, sem juros). */
    private static function sufixoDeJuros(?string $taxa): string
    {
        if ($taxa === null || bccomp($taxa, '0', 4) <= 0) {
            return '';
        }

        $texto = str_contains($taxa, '.') ? rtrim(rtrim($taxa, '0'), '.') : $taxa;

        return ' · juros ' . str_replace('.', ',', $texto) . '% a.m.';
    }

    private static function quandoSemJuros(PastaPagamento $pagamento, \DateTimeImmutable $hoje): string
    {
        if ($pagamento->estaPago()) {
            return 'pago em ' . $pagamento->getPagoEm()?->format('d/m/Y');
        }

        $vencimento = $pagamento->getVencimento();
        if ($pagamento->estaPrevisto() || $vencimento === null) {
            // Congelado por correção, o previsto perde o percentual e fica só a condição.
            return $pagamento->getPercentual() !== null
                ? self::percentualTexto($pagamento->getPercentual()) . '% sobre o total contratado · condicionado ao resultado'
                : 'condicionado ao resultado';
        }

        $dias       = (int) $hoje->diff($vencimento)->format('%r%a');
        $data       = $vencimento->format('d/m/Y');

        if ($pagamento->estaVencido($hoje)) {
            $atraso = abs($dias);

            return 'venceu ' . $data . ' · há ' . $atraso . ($atraso === 1 ? ' dia' : ' dias');
        }

        return 'vence ' . $data . match (true) {
            $dias === 0 => ' · hoje',
            $dias <= 7  => ' · em ' . $dias . ($dias === 1 ? ' dia' : ' dias'),
            default     => '',
        };
    }
}
