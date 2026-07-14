<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\LinhaParcelaGerada;
use App\Cobranca\Enum\Periodicidade;
use App\Cobranca\Exception\ParcelamentoInvalidoException;

/**
 * Gerador de parcelamento de acordo (Ajuste 7), fonte ÚNICA da aritmética — aritmética 100% em
 * centavos inteiros, sem floats. Divide o total (menos a entrada) em N parcelas iguais; a SOBRA de
 * centavos cai na PRIMEIRA parcela (decisão D3). A entrada é tratada à parte pelo UseCase (1ª
 * obrigação do acordo); este serviço só gera as parcelas regulares.
 */
final class GeradorParcelamento
{
    /**
     * @return LinhaParcelaGerada[] as N parcelas (sem a entrada), com Σ valores == (total − entrada)
     *
     * @throws ParcelamentoInvalidoException
     */
    public function gerar(
        int $totalNegociado,
        int $valorEntrada,
        int $quantidadeParcelas,
        \DateTimeImmutable $vencimentoPrimeira,
        Periodicidade $periodicidade,
    ): array {
        if ($totalNegociado <= 0) {
            throw ParcelamentoInvalidoException::totalInvalido($totalNegociado);
        }

        if ($valorEntrada < 0) {
            throw ParcelamentoInvalidoException::entradaNegativa($valorEntrada);
        }

        if ($quantidadeParcelas < 1) {
            throw ParcelamentoInvalidoException::quantidadeInvalida($quantidadeParcelas);
        }

        if ($valorEntrada >= $totalNegociado) {
            throw ParcelamentoInvalidoException::entradaConsomeTotal($valorEntrada, $totalNegociado);
        }

        $totalParcelado = $totalNegociado - $valorEntrada;

        // Cada parcela precisa ser positiva (a 1ª absorve a sobra; as demais recebem `base`).
        if ($totalParcelado < $quantidadeParcelas) {
            throw ParcelamentoInvalidoException::valorPorParcelaInsuficiente($totalParcelado, $quantidadeParcelas);
        }

        $base = intdiv($totalParcelado, $quantidadeParcelas);
        $resto = $totalParcelado - $base * $quantidadeParcelas;

        $linhas = [];

        for ($k = 1; $k <= $quantidadeParcelas; ++$k) {
            // D3: a primeira parcela absorve a sobra de centavos; as demais ficam com a base.
            $valor = $k === 1 ? $base + $resto : $base;

            $linhas[] = new LinhaParcelaGerada(
                sprintf('Parcela %d/%d', $k, $quantidadeParcelas),
                $valor,
                $periodicidade->proximoVencimento($vencimentoPrimeira, $k - 1),
            );
        }

        // Guard defensivo: por construção a soma fecha; falha aqui indica bug no cálculo.
        $soma = array_sum(array_map(static fn (LinhaParcelaGerada $linha): int => $linha->valor, $linhas));

        if ($soma !== $totalParcelado) {
            throw ParcelamentoInvalidoException::somaNaoFecha($soma, $totalParcelado);
        }

        return $linhas;
    }
}
