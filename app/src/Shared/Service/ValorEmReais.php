<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * Converte o que o humano digita em reais para o decimal que o Doctrine grava.
 *
 * A conversão é feita só com string — dinheiro não passa por float em ponto
 * nenhum do caminho. Entrada em pt-BR ("12.860,00", "R$ 12.860,00", "12860"),
 * saída no formato de `decimal(15,2)` ("12860.00").
 *
 * Nasceu dentro do `AtualizarValorCausaUseCase`, que já resolvia isso sozinho;
 * saiu de lá quando o pagamento da pasta passou a precisar da MESMA conversão.
 * Duas cópias da regra de separador decimal é como se ganha divergência de
 * centavos entre dois campos da mesma tela.
 */
final class ValorEmReais
{
    /** decimal(15,2) comporta 13 dígitos antes da vírgula. */
    private const MAX_DIGITOS_INTEIROS = 13;

    /**
     * Texto em branco vira nulo — que é diferente de R$ 0,00: nulo é "não sei",
     * zero é um valor. Quem chama decide se aceita o nulo.
     *
     * @param string $rotulo como o campo se chama nas mensagens de erro
     *
     * @throws \InvalidArgumentException quando o texto digitado não é um valor em reais
     */
    public static function normalizar(?string $entrada, string $rotulo = 'valor'): ?string
    {
        $texto = str_replace(
            ['R$', 'r$', ' ', "\u{00A0}", "\u{202F}"],
            '',
            trim((string) $entrada)
        );

        if ($texto === '') {
            return null;
        }

        if (str_starts_with($texto, '-')) {
            throw new \InvalidArgumentException(sprintf('O %s não pode ser negativo.', $rotulo));
        }

        if (preg_match('/[^0-9.,]/', $texto) === 1) {
            throw new \InvalidArgumentException('Informe apenas números, como 12.860,00.');
        }

        [$inteiro, $decimal] = self::separarParteInteiraEDecimal($texto);

        if ($inteiro === '' && $decimal === '') {
            throw new \InvalidArgumentException('Informe apenas números, como 12.860,00.');
        }

        if (strlen($decimal) > 2) {
            throw new \InvalidArgumentException('Use no máximo duas casas decimais, como 12.860,00.');
        }

        $inteiro = ltrim($inteiro, '0');
        if ($inteiro === '') {
            $inteiro = '0';
        }

        if (strlen($inteiro) > self::MAX_DIGITOS_INTEIROS) {
            throw new \InvalidArgumentException(sprintf('Valor acima do limite aceito para o %s.', $rotulo));
        }

        return $inteiro . '.' . str_pad($decimal, 2, '0', STR_PAD_RIGHT);
    }

    /**
     * O decimal do banco em centavos inteiros, que é a única forma segura de
     * somar dinheiro. "12860.00" → 1286000.
     */
    public static function paraCentavos(?string $decimal): int
    {
        if ($decimal === null || $decimal === '') {
            return 0;
        }

        [$inteiro, $centavos] = array_pad(explode('.', $decimal, 2), 2, '00');

        return (int) $inteiro * 100 + (int) str_pad(substr($centavos, 0, 2), 2, '0', STR_PAD_RIGHT);
    }

    /** Centavos inteiros de volta para o decimal de `decimal(15,2)`. */
    public static function deCentavos(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), abs($centavos % 100));
    }

    /**
     * Decide quem é separador de milhar e quem é separador decimal.
     *
     * Com vírgula presente, ela manda: o ponto é milhar. Sem vírgula, o ponto só
     * é milhar quando forma grupos exatos de três dígitos ("12.860", "1.234.567");
     * caso contrário é decimal ("12860.50"), que é como sai do teclado numérico.
     *
     * @return array{string, string}
     */
    private static function separarParteInteiraEDecimal(string $texto): array
    {
        if (str_contains($texto, ',')) {
            if (substr_count($texto, ',') > 1) {
                throw new \InvalidArgumentException('Informe apenas números, como 12.860,00.');
            }

            [$inteiro, $decimal] = explode(',', $texto);

            if (str_contains($decimal, '.')) {
                throw new \InvalidArgumentException('Informe apenas números, como 12.860,00.');
            }

            return [str_replace('.', '', $inteiro), $decimal];
        }

        // Grupo de milhar nunca começa em zero: "0.500" não é meio milhar, é uma
        // tentativa de decimal com três casas — que o passo seguinte recusa, em vez
        // de virar R$ 500,00 em silêncio.
        if (preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $texto) === 1) {
            return [str_replace('.', '', $texto), ''];
        }

        $pontos = substr_count($texto, '.');

        if ($pontos === 0) {
            return [$texto, ''];
        }

        if ($pontos === 1) {
            [$inteiro, $decimal] = explode('.', $texto);

            return [$inteiro, $decimal];
        }

        throw new \InvalidArgumentException('Informe apenas números, como 12.860,00.');
    }
}
