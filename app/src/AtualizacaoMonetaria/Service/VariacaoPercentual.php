<?php

declare(strict_types=1);

namespace App\AtualizacaoMonetaria\Service;

/**
 * A forma canônica de uma variação percentual mensal: sinal + parte inteira sem zeros à esquerda +
 * exatamente 6 casas — o que `numeric(12,6)` guarda e devolve.
 *
 * Existe como classe própria, e não como método da entidade, porque **três** peças precisam da mesma
 * definição e uma delas não pode depender do Doctrine: o cliente do BCB (para recusar na fronteira o
 * que não seria armazenável), a `IndiceMonetario` (para gravar e comparar) e a `TabelaIndices` (que
 * é objeto puro em memória, entregue ao motor de cálculo). Regra duplicada em três lugares é regra
 * que diverge.
 *
 * Nada aqui usa BCMath: comparar duas leituras do mesmo índice é comparar strings depois de
 * canonizadas — o PostgreSQL devolve '0.140000' onde o BCB mandou '0.14', e comparar as formas cruas
 * acusaria revisão de índice em toda reimportação.
 */
final class VariacaoPercentual
{
    /** Precisão de `numeric(12,6)`: 12 dígitos no total, 6 deles decimais → 6 inteiros. */
    private const CASAS_DECIMAIS = 6;
    private const DIGITOS_INTEIROS = 6;

    /**
     * @throws \InvalidArgumentException quando o valor não é um decimal simples ou não caberia
     *                                   fielmente em `numeric(12,6)`
     */
    public static function canonizar(string $valor): string
    {
        $texto = trim($valor);

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $texto, $partes) !== 1) {
            throw new \InvalidArgumentException(sprintf('Variação "%s" não é um decimal simples.', $valor));
        }

        [, $sinal, $inteiro, $decimal] = $partes + [3 => ''];

        if (\strlen(ltrim($inteiro, '0')) > self::DIGITOS_INTEIROS) {
            throw new \InvalidArgumentException(sprintf(
                'Variação "%s" excede a parte inteira de numeric(12,6).',
                $valor,
            ));
        }

        // Arredondar por conta própria esconderia a perda e o valor gravado deixaria de ser o publicado.
        if (\strlen($decimal) > self::CASAS_DECIMAIS) {
            throw new \InvalidArgumentException(sprintf(
                'Variação "%s" tem mais de %d casas decimais e não cabe em numeric(12,6).',
                $valor,
                self::CASAS_DECIMAIS,
            ));
        }

        $inteiro = ltrim($inteiro, '0');
        $inteiro = $inteiro === '' ? '0' : $inteiro;
        $decimal = str_pad($decimal, self::CASAS_DECIMAIS, '0');

        // '-0.000000' e '0.000000' são o mesmo número: sem isso, zero negativo passaria por revisão.
        $negativo = $sinal === '-' && ($inteiro !== '0' || $decimal !== str_repeat('0', self::CASAS_DECIMAIS));

        return ($negativo ? '-' : '') . $inteiro . '.' . $decimal;
    }

    /**
     * O valor caberia em `numeric(12,6)` sem perda? Pergunta sem lançar — é o que o cliente do BCB
     * usa para recusar a série na fronteira, em vez de deixar a exceção estourar lá adiante, na
     * construção da entidade, com metade da importação já feita.
     */
    public static function ehArmazenavel(string $valor): bool
    {
        try {
            self::canonizar($valor);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
