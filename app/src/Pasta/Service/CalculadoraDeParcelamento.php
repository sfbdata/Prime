<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * A conta do modal "Adicionar pagamento" (desenho 1.2.3, regra no dc L.3470-3500):
 * quanto vale cada parcela e em que dia ela vence.
 *
 * Dinheiro entra e sai em CENTAVOS INTEIROS. Onde a conta precisa de fração (a
 * potência da tabela Price), ela é feita em bcmath com escala alta — nunca em
 * float. O navegador faz a mesma conta só para mostrar a prévia; quem grava é
 * esta classe, e o que vale é o que ela devolve.
 *
 * REGRA DO ARREDONDAMENTO (a mesma do desenho):
 *  - sem juros: cada parcela é P/n arredondado ao centavo (meio para cima) e a
 *    ÚLTIMA leva a diferença — a soma das parcelas é EXATAMENTE o principal P;
 *  - com juros (Price): pmt = P·i / (1 − (1+i)^−n). Cada parcela é o pmt
 *    arredondado ao centavo; o total a pagar é arred(pmt × n), e a ÚLTIMA
 *    parcela é esse total menos as n−1 anteriores. A soma das parcelas é
 *    EXATAMENTE arred(pmt × n) — o valor financiado com os juros.
 * Em ambos os casos o resíduo de arredondamento fica só na última parcela.
 *
 * Stateless: pode ser injetada ou instanciada direto (é o que o teste faz).
 */
final class CalculadoraDeParcelamento
{
    /** Escala das contas intermediárias do bcmath. Muito acima do centavo de propósito. */
    private const ESCALA = 30;

    /**
     * Valores das parcelas, em centavos, na ordem.
     *
     * @param int    $principalCentavos    o que será parcelado (já sem a entrada), > 0
     * @param int    $quantidade           número de parcelas, >= 1
     * @param string $taxaMensalPercentual taxa ao mês em PERCENTUAL decimal ("1", "1.5"); "0" = sem juros
     *
     * @return list<int>
     *
     * @throws \InvalidArgumentException quando alguma parcela sairia com R$ 0,00 ou menos
     */
    public function parcelas(int $principalCentavos, int $quantidade, string $taxaMensalPercentual = '0'): array
    {
        if ($principalCentavos <= 0) {
            throw new \InvalidArgumentException('Informe um valor maior que zero.');
        }

        if ($quantidade < 1) {
            throw new \InvalidArgumentException('Informe pelo menos uma parcela.');
        }

        $semJuros = bccomp($taxaMensalPercentual, '0', self::ESCALA) <= 0;

        [$parcela, $total] = $semJuros
            ? [$this->divisaoSimples($principalCentavos, $quantidade), $principalCentavos]
            : $this->price($principalCentavos, $quantidade, $taxaMensalPercentual);

        $ultima = $total - $parcela * ($quantidade - 1);

        // Valor pequeno demais para tantas parcelas: com o arredondamento ao
        // centavo, a parcela (ou a última, que absorve a diferença) zeraria ou
        // ficaria negativa. Lançamento de R$ 0,00 é linha que não deveria existir.
        if ($parcela <= 0 || $ultima <= 0) {
            throw new \InvalidArgumentException(
                sprintf('O valor é pequeno demais para %d parcelas. Diminua o número de parcelas.', $quantidade)
            );
        }

        $valores = array_fill(0, $quantidade, $parcela);
        $valores[$quantidade - 1] = $ultima;

        return $valores;
    }

    /**
     * Valor em centavos de um percentual sobre uma base, arredondado ao centavo
     * (meio para cima). "20" de R$ 12.860,00 → 257200.
     *
     * @param string $percentual percentual decimal ("20", "12.5")
     */
    public function percentualDe(int $baseCentavos, string $percentual): int
    {
        $bruto = bcdiv(bcmul((string) $baseCentavos, $percentual, self::ESCALA), '100', self::ESCALA);

        return $this->arredondarAoInteiro($bruto);
    }

    /**
     * Vencimentos mensais a partir do primeiro, ancorados no DIA do primeiro.
     *
     * Mês sem aquele dia cai no último dia do mês — e o mês seguinte volta ao dia
     * original: 31/01 → 28/02 (29 no bissexto) → 31/03 → 30/04. Somar "+1 month"
     * do PHP faria 31/01 virar 03/03, e um vencimento inventado é pior que um erro.
     *
     * @return list<\DateTimeImmutable>
     */
    public function vencimentos(\DateTimeImmutable $primeiro, int $quantidade): array
    {
        $ano = (int) $primeiro->format('Y');
        $mes = (int) $primeiro->format('n');
        $dia = (int) $primeiro->format('j');

        $datas = [];
        for ($k = 0; $k < $quantidade; $k++) {
            $indice  = ($mes - 1) + $k;
            $anoK    = $ano + intdiv($indice, 12);
            $mesK    = ($indice % 12) + 1;
            // Último dia do mês sem a extensão `calendar`: o formato `t` do dia 1.
            $ultimo  = (int) $primeiro->setDate($anoK, $mesK, 1)->format('t');
            $datas[] = $primeiro->setDate($anoK, $mesK, min($dia, $ultimo))->setTime(0, 0);
        }

        return $datas;
    }

    /**
     * P/n ao centavo, meio para cima, só com inteiros: (2P + n) div 2n.
     */
    private function divisaoSimples(int $principalCentavos, int $quantidade): int
    {
        return intdiv(2 * $principalCentavos + $quantidade, 2 * $quantidade);
    }

    /**
     * @return array{int, int} [parcela arredondada, total a pagar arredondado], em centavos
     */
    private function price(int $principalCentavos, int $quantidade, string $taxaMensalPercentual): array
    {
        $i     = bcdiv($taxaMensalPercentual, '100', self::ESCALA);
        $fator = bcpow(bcadd('1', $i, self::ESCALA), (string) $quantidade, self::ESCALA);

        // pmt = P·i·(1+i)^n / ((1+i)^n − 1) — a mesma fórmula de P·i/(1−(1+i)^−n),
        // sem a potência negativa. Em centavos: o resultado já sai em centavos.
        $pmt = bcdiv(
            bcmul(bcmul((string) $principalCentavos, $i, self::ESCALA), $fator, self::ESCALA),
            bcsub($fator, '1', self::ESCALA),
            self::ESCALA,
        );

        return [
            $this->arredondarAoInteiro($pmt),
            $this->arredondarAoInteiro(bcmul($pmt, (string) $quantidade, self::ESCALA)),
        ];
    }

    /** Meio para cima, para valor não negativo: soma 0,5 e trunca (bcadd com escala 0 trunca). */
    private function arredondarAoInteiro(string $valor): int
    {
        return (int) bcadd($valor, '0.5', 0);
    }
}
