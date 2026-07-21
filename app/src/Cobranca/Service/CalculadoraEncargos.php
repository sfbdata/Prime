<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Exception\EncargosInexequiveisException;

/**
 * Motor de cálculo dos encargos de uma obrigação em atraso (spec "encargos configuráveis em
 * cascata" §3/§6): dado o principal, o vencimento, a configuração EFETIVA e uma data de
 * referência, devolve `{juros, multa, correcao, honorarios}` em CENTAVOS inteiros.
 *
 * Serviço PURO: sem construtor, sem dependências, sem I/O e — de propósito — sem
 * `new \DateTimeImmutable()` interno. A data de referência é SEMPRE injetada, para que o cálculo
 * seja determinístico e testável (é dinheiro: o mesmo input tem de dar o mesmo output sempre).
 *
 * Toda a aritmética é INTEIRA. `float` é proibido no caminho do dinheiro: percentuais chegam aqui
 * já em basis points (ver `ConfigEncargos`) e as divisões usam `intdiv` com arredondamento
 * explícito. Os dois arredondamentos NÃO são o mesmo, e isso é comprovado empiricamente contra
 * 4.413 linhas reais (Apêndice A da spec): os juros arredondam MEIO-PARA-BAIXO, a multa/correção/
 * honorários arredondam MEIO-PARA-CIMA.
 *
 * A fórmula (default TOPLIFE, tudo configurável):
 *   d = dias inteiros de atraso (calendário), P = principal em centavos
 *   juros      = half_down(P · taxaJurosMensalBp · d / (30 · 10000))    — simples, base principal
 *   multa      = half_up(baseMulta · taxaMultaBp / 10000)               — base principal (fixa)
 *   correcao   = half_up(baseCorrecao · taxaCorrecaoBp / 10000)         — taxa default 0
 *   honorarios = half_up((P+juros+multa+correcao) · taxaHonBp / 10000) se d > carência, senão 0
 */
final class CalculadoraEncargos
{
    /** Denominador dos basis points: 10000 bp = 100%. */
    private const BASIS_POINTS = 10000;

    /** Mês comercial de 30 dias — é assim que a contabilidade calcula o pró-rata (Apêndice A). */
    private const DIAS_DO_MES = 30;

    /**
     * Calcula os quatro encargos para a data de referência informada.
     *
     * Honorários são calculados à parte dos demais: a carência de honorários é INDEPENDENTE da
     * tolerância de juros/multa (a operação real tem carência de ~30 dias só para honorários,
     * enquanto juros e multa valem desde o 1º dia de atraso).
     *
     * @return array{juros:int, multa:int, correcao:int, honorarios:int}
     */
    public function calcular(
        int $valorOriginalCentavos,
        \DateTimeImmutable $vencimento,
        ConfigEncargos $config,
        \DateTimeImmutable $dataReferencia,
    ): array {
        $nenhum = ['juros' => 0, 'multa' => 0, 'correcao' => 0, 'honorarios' => 0];

        // Principal não positivo (obrigação zerada/estorno): não há base sobre a qual incidir.
        if ($valorOriginalCentavos <= 0) {
            return $nenhum;
        }

        $dias = $this->diasDeAtraso($vencimento, $dataReferencia);

        // Em dia (ou vencendo hoje): nenhum encargo, nem honorário. Garantia explícita de que
        // d == 0 ⇒ honorários 0, independente de como a carência estiver configurada.
        if ($dias === 0) {
            return $nenhum;
        }

        $principal = $valorOriginalCentavos;
        $juros = 0;
        $multa = 0;
        $correcao = 0;

        // Tolerância de juros/multa (default 0 = valem do 1º dia). Corte estrito: dentro da
        // tolerância não há juros nem multa nem correção.
        if ($dias > $config->toleranciaJurosMultaDias) {
            $juros = $this->calcularJuros($principal, $dias, $config);

            $baseMulta = $config->baseMulta === BaseEncargo::Principal
                ? $principal
                : $principal + $juros;
            $multa = self::valorDeTaxa($baseMulta, $config->taxaMultaBp);

            $baseCorrecao = $config->baseCorrecao === BaseEncargo::Principal
                ? $principal
                : $principal + $juros + $multa;
            $correcao = self::valorDeTaxa($baseCorrecao, $config->taxaCorrecaoBp);
        }

        // Honorários sobre a base já calculada; a regra (carência, base composta/principal, half-up)
        // mora em `honorarios()`, reutilizada por quem trava um encargo digitado à mão (F6).
        $honorarios = $this->honorarios($principal, $juros, $multa, $correcao, $config, $dias);

        return [
            'juros' => $juros,
            'multa' => $multa,
            'correcao' => $correcao,
            'honorarios' => $honorarios,
        ];
    }

    /**
     * Honorários em centavos sobre uma base de juros/multa/correção JÁ CONHECIDOS — NÃO recalcula os
     * juros. É o mesmo cálculo interno de `calcular()` (que agora chama este método), isolado para o
     * caminho em que o gestor DIGITA juros/multa/correção à mão e trava a obrigação (F6): os honorários
     * não têm campo no formulário, então são completados aqui sobre a base digitada — senão travariam em
     * zero (o bug bloqueante que a F4 encontrou).
     *
     * Corte ESTRITO da carência: `dias <= carência` ⇒ 0 (carência 30 ⇒ honorário só a partir do 31º
     * dia). Base COMPOSTA soma principal + juros + multa + correção; base PRINCIPAL usa só o principal.
     * Arredondamento meio-para-cima, via `valorDeTaxa` (que degrada para 0 em base ou taxa não positiva).
     */
    public function honorarios(
        int $principalCentavos,
        int $jurosCentavos,
        int $multaCentavos,
        int $correcaoCentavos,
        ConfigEncargos $config,
        int $dias,
    ): int {
        if ($dias <= $config->carenciaHonorariosDias) {
            return 0;
        }

        $base = $config->baseHonorarios === BaseEncargo::Composta
            ? $principalCentavos + $jurosCentavos + $multaCentavos + $correcaoCentavos
            : $principalCentavos;

        return self::valorDeTaxa($base, $config->taxaHonorariosBp);
    }

    /**
     * Dias inteiros de atraso (calendário), nunca negativo. Compara apenas as DATAS — a hora é
     * zerada nos dois lados, senão uma obrigação vencida hoje de manhã já contaria 0/1 dia
     * conforme o horário da execução do cron, e o valor oscilaria sem motivo.
     */
    public function diasDeAtraso(\DateTimeImmutable $vencimento, \DateTimeImmutable $dataReferencia): int
    {
        $venceEm = $vencimento->setTime(0, 0, 0);
        $referencia = $dataReferencia->setTime(0, 0, 0);

        if ($referencia <= $venceEm) {
            return 0;
        }

        $dias = $venceEm->diff($referencia)->days;

        return $dias === false ? 0 : max(0, $dias);
    }

    /**
     * R$ a partir de %: `base · taxaBp / 10000`, arredondamento meio-para-cima. É a conversão
     * "% → valor" usada pela UI (% ↔ R$) e pela própria fórmula de multa/correção/honorários.
     */
    public static function valorDeTaxa(int $baseCentavos, int $taxaBp): int
    {
        if ($baseCentavos <= 0 || $taxaBp <= 0) {
            return 0;
        }

        return self::arredondarMeioParaCima($baseCentavos * $taxaBp, self::BASIS_POINTS);
    }

    /**
     * % (em bp) a partir de R$: `valor · 10000 / base`, arredondamento meio-para-cima. É o sentido
     * inverso da edição na UI (o gestor digita o valor em reais e o sistema mostra a taxa). Devolve
     * 0 quando a base não é positiva — não existe percentual sobre base zero.
     */
    public static function taxaDeValor(int $baseCentavos, int $valorCentavos): int
    {
        if ($baseCentavos <= 0 || $valorCentavos <= 0) {
            return 0;
        }

        return self::arredondarMeioParaCima($valorCentavos * self::BASIS_POINTS, $baseCentavos);
    }

    /**
     * INVERSO do juros: a taxa mensal (bp) que reproduz `valorCentavos` de juros para `dias` de atraso,
     * arredondando meio-para-cima. Espelho de `taxaDeValor` considerando o pró-rata `dias/30`
     * (spec ao-vivo §7). Serve à edição "digitei o R$ de juros → guarda a % equivalente à data de hoje".
     * Degrada para 0 quando não há base sobre a qual derivar (principal/dias/valor não positivos).
     */
    public static function taxaJurosBpDeValor(int $principalCentavos, int $dias, int $valorCentavos): int
    {
        if ($principalCentavos <= 0 || $dias <= 0 || $valorCentavos <= 0) {
            return 0;
        }

        // bp = valor · (30·10000) / (P · dias), meio-para-cima. Numerador ~ valor·3e5: cabe em int64.
        return self::arredondarMeioParaCima(
            $valorCentavos * self::DIAS_DO_MES * self::BASIS_POINTS,
            $principalCentavos * $dias,
        );
    }

    /**
     * Juros de mora em centavos.
     *
     * SIMPLES (default, único regime provado contra dados reais): pró-rata diária sobre o
     * principal puro, mês comercial de 30 dias, arredondamento MEIO-PARA-BAIXO.
     *
     * COMPOSTO: capitaliza a cada mês inteiro de atraso (meio-para-cima, como juro creditado) e
     * aplica o resto de dias pró-rata sobre o montante acumulado. ATENÇÃO: este regime NÃO é o
     * default TOPLIFE e NÃO está provado contra dados reais da contabilidade — existe porque o
     * produto é SaaS e cada escritório configura o seu. Antes de usá-lo em produção, valide contra
     * um extrato real, como foi feito com o simples (Apêndice A da spec).
     */
    private function calcularJuros(int $principal, int $dias, ConfigEncargos $config): int
    {
        if ($config->taxaJurosMensalBp <= 0) {
            return 0;
        }

        if ($config->regimeJuros === RegimeJuros::Simples) {
            return self::arredondarMeioParaBaixo(
                $principal * $config->taxaJurosMensalBp * $dias,
                self::DIAS_DO_MES * self::BASIS_POINTS,
            );
        }

        $mesesInteiros = intdiv($dias, self::DIAS_DO_MES);
        $diasRestantes = $dias % self::DIAS_DO_MES;
        $montante = $principal;

        // Teto de segurança: acima dele a próxima multiplicação estoura PHP_INT_MAX, o inteiro vira
        // float e `strict_types` derruba a chamada com TypeError. Checar ANTES de multiplicar é o
        // único jeito de errar alto e explícito em vez de baixo e silencioso.
        $tetoMontante = intdiv(\PHP_INT_MAX, $config->taxaJurosMensalBp * max(1, $diasRestantes));

        for ($mes = 0; $mes < $mesesInteiros; ++$mes) {
            if ($montante > $tetoMontante) {
                throw new EncargosInexequiveisException($principal, $dias, $config->taxaJurosMensalBp);
            }

            $montante += self::arredondarMeioParaCima(
                $montante * $config->taxaJurosMensalBp,
                self::BASIS_POINTS,
            );
        }

        if ($montante > $tetoMontante) {
            throw new EncargosInexequiveisException($principal, $dias, $config->taxaJurosMensalBp);
        }

        $montante += self::arredondarMeioParaBaixo(
            $montante * $config->taxaJurosMensalBp * $diasRestantes,
            self::DIAS_DO_MES * self::BASIS_POINTS,
        );

        return $montante - $principal;
    }

    /**
     * Divisão inteira com arredondamento MEIO-PARA-CIMA (2,4 → 2 · 2,5 → 3 · 2,6 → 3). Mesmo idioma
     * de `CalculadoraHonorarios::arredondarFracao`, para o módulo inteiro arredondar igual.
     * Denominador não positivo degrada para 0 (não existe divisão válida); numerador negativo não
     * ocorre no caminho do dinheiro e também degrada para 0 em vez de arredondar para o lado errado.
     */
    private static function arredondarMeioParaCima(int $numerador, int $denominador): int
    {
        if ($denominador <= 0 || $numerador <= 0) {
            return 0;
        }

        return intdiv($numerador + intdiv($denominador, 2), $denominador);
    }

    /**
     * Divisão inteira com arredondamento MEIO-PARA-BAIXO (2,4 → 2 · 2,5 → 2 · 2,6 → 3): no empate
     * EXATO de meio centavo, fica embaixo. É o arredondamento dos juros, confirmado empiricamente
     * contra os extratos reais (Apêndice A da spec).
     *
     * Implementado por quociente/resto em vez do atalho `intdiv($n + intdiv($d,2) - 1, $d)`: aquele
     * atalho só vale para denominador PAR (com `$d` ímpar ele erra — ex.: 2/3 daria 0 em vez de 1).
     * Aqui o denominador é sempre 30·10000, mas um helper de dinheiro não pode ter borda escondida.
     * Comparar `resto·2` com o denominador é exato e vale para qualquer denominador positivo.
     */
    private static function arredondarMeioParaBaixo(int $numerador, int $denominador): int
    {
        if ($denominador <= 0 || $numerador <= 0) {
            return 0;
        }

        $quociente = intdiv($numerador, $denominador);
        $resto = $numerador - ($quociente * $denominador);

        return ($resto * 2) > $denominador ? $quociente + 1 : $quociente;
    }
}
