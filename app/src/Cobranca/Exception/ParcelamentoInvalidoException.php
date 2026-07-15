<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Parâmetros inválidos ao gerar um parcelamento de acordo (Ajuste 7): total não-positivo, entrada
 * negativa, quantidade de parcelas < 1, entrada que consome todo o total, ou total baixo demais para
 * gerar N parcelas positivas. Erro de regra do gerador (centavos inteiros) — nunca deve chegar ao banco.
 */
final class ParcelamentoInvalidoException extends \DomainException
{
    public static function totalInvalido(int $total): self
    {
        return new self(sprintf('O total do parcelamento deve ser positivo (recebido %d centavos).', $total));
    }

    public static function entradaNegativa(int $entrada): self
    {
        return new self(sprintf('A entrada não pode ser negativa (recebido %d centavos).', $entrada));
    }

    public static function quantidadeInvalida(int $quantidade): self
    {
        return new self(sprintf('O parcelamento exige ao menos 1 parcela (recebido %d).', $quantidade));
    }

    public static function entradaConsomeTotal(int $entrada, int $total): self
    {
        return new self(sprintf(
            'A entrada (%d centavos) não pode ser maior ou igual ao total (%d centavos) quando há parcelas.',
            $entrada,
            $total,
        ));
    }

    public static function valorPorParcelaInsuficiente(int $totalParcelado, int $quantidade): self
    {
        return new self(sprintf(
            'O valor a parcelar (%d centavos) é baixo demais para gerar %d parcelas positivas.',
            $totalParcelado,
            $quantidade,
        ));
    }

    public static function somaNaoFecha(int $soma, int $totalParcelado): self
    {
        return new self(sprintf(
            'A soma das parcelas geradas (%d centavos) não fecha com o total a parcelar (%d centavos).',
            $soma,
            $totalParcelado,
        ));
    }

    /** INV-B no servidor: entrada + Σ parcelas tem de fechar com o total negociado informado. */
    public static function totalNegociadoDivergente(int $somaEntradaParcelas, int $totalNegociado): self
    {
        return new self(sprintf(
            'A entrada mais as parcelas (%d centavos) não fecham com o total negociado (%d centavos).',
            $somaEntradaParcelas,
            $totalNegociado,
        ));
    }
}
