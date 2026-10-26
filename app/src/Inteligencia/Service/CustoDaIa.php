<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\Enum\ModeloDeIa;

/**
 * Custo ESTIMADO da BlueJus IA, em centavos de dólar, pelo preço de tabela da Anthropic
 * ({@see ModeloDeIa::precoPorMilhao()}; spec ia-anthropic-ligar §6 e §10.2). Não é a fatura: é o que o orçamento
 * mensal do escritório e os painéis de consumo usam.
 *
 * - Modelo gravado que não está no enum (ou nulo) paga o preço do mais caro (Opus 5.5): na dúvida, o orçamento
 *   trava antes, nunca depois.
 * - Com a região de inferência `us` (`IA_INFERENCE_GEO`), a Anthropic cobra 1,1x: o fator entra aqui.
 * - A conta é inteira (centavos por milhão de tokens) e arredonda PARA CIMA uma vez só, no fim: somar o consumo de
 *   várias linhas não acumula arredondamento.
 */
final class CustoDaIa
{
    private const TOKENS_POR_PRECO = 1_000_000;
    /** O fator 1,1 da região `us` vira 11/10 para a conta continuar inteira. */
    private const DECIMOS_SEM_ACRESCIMO = 10;
    private const DECIMOS_COM_ACRESCIMO_US = 11;

    private readonly bool $regiaoUs;

    public function __construct(string $regiaoDeInferencia)
    {
        $this->regiaoUs = strtolower(trim($regiaoDeInferencia)) === 'us';
    }

    /** Custo de UMA análise. Tokens nulos contam zero. */
    public function centavosDaAnalise(?string $modelo, ?int $tokensEntrada, ?int $tokensSaida): int
    {
        return $this->centavosDoConsumo([['modelo' => $modelo, 'tokensEntrada' => (int) $tokensEntrada, 'tokensSaida' => (int) $tokensSaida]]);
    }

    /**
     * Custo de um conjunto de linhas já somadas por modelo (a consulta agrupada do repositório).
     *
     * @param iterable<array{modelo: ?string, tokensEntrada: int, tokensSaida: int}> $linhas
     */
    public function centavosDoConsumo(iterable $linhas): int
    {
        $numerador = 0;
        foreach ($linhas as $linha) {
            $numerador += $this->numeradorDaLinha($linha['modelo'], $linha['tokensEntrada'], $linha['tokensSaida']);
        }

        return self::arredondarParaCima($numerador * $this->fatorEmDecimos(), self::TOKENS_POR_PRECO * self::DECIMOS_SEM_ACRESCIMO);
    }

    /** Tokens × centavos por milhão, ainda sem dividir pelo milhão. */
    private function numeradorDaLinha(?string $modelo, int $tokensEntrada, int $tokensSaida): int
    {
        $preco = self::modeloCobrado($modelo)->precoPorMilhao();

        return max(0, $tokensEntrada) * self::centavos($preco['entrada']) + max(0, $tokensSaida) * self::centavos($preco['saida']);
    }

    private static function modeloCobrado(?string $modelo): ModeloDeIa
    {
        return ModeloDeIa::tryFrom((string) $modelo) ?? ModeloDeIa::Opus55;
    }

    private static function centavos(float $dolares): int
    {
        return (int) round($dolares * 100);
    }

    private function fatorEmDecimos(): int
    {
        return $this->regiaoUs ? self::DECIMOS_COM_ACRESCIMO_US : self::DECIMOS_SEM_ACRESCIMO;
    }

    private static function arredondarParaCima(int $numerador, int $denominador): int
    {
        return intdiv($numerador + $denominador - 1, $denominador);
    }
}
