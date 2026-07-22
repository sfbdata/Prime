<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;

/**
 * Resolve a configuração EFETIVA de encargos percorrendo a cascata de três níveis
 * Carteira → Objeto → Obrigação (spec "cascata de encargos ao vivo sem snapshot" §3.1).
 *
 * A resolução é CAMPO A CAMPO, não bloco a bloco: um override que preenche apenas a taxa de juros
 * herda todo o resto do nível de cima. Bloco a bloco seria uma armadilha — sobrepor a taxa de juros
 * zeraria silenciosamente multa e honorários.
 *
 * Serviço puro e stateless (sem I/O): recebe entidades já carregadas e devolve um DTO imutável.
 *
 * O NÍVEL 2 (o "meio") da cascata é o OBJETO, não mais o Caso (spec #9-T1 — reverte parcialmente a
 * decisão D2/§18.2/§18.3 da feature de encargos): `resolverDoCaso` DELEGA integralmente para
 * `resolverDoObjeto` e não lê mais nenhuma coluna de config do `CasoCobranca` — nem os overrides de
 * taxa/base/carência/tolerância, nem o antigo snapshot de honorários (`formaHonorarios`/
 * `percentualHonorarios`). Essas colunas do Caso continuam existindo (coluna-sombra por 1 release,
 * rollback seguro — spec §5), mas viram MORTAS para fins de cálculo. Honorários agora cascateiam
 * como qualquer outro campo: `taxaHonorariosBp` é override direto do Objeto (bp, espelhando a
 * Obrigação — decisão D2 supersedida), e null herda a carteira — AO VIVO, sem congelar.
 */
final class ResolvedorConfigEncargos
{
    /**
     * Configuração efetiva de uma obrigação: parte do Caso resolvido e aplica os overrides da
     * própria obrigação. Navegação nula degrada para `neutra()` — obrigação órfã de caso não pode
     * derrubar um cálculo de dinheiro com erro fatal.
     */
    public function resolver(Obrigacao $obrigacao): ConfigEncargos
    {
        $caso = $obrigacao->getCaso();
        $base = $caso === null ? ConfigEncargos::neutra() : $this->resolverDoCaso($caso);

        return $this->aplicarObrigacao($base, $obrigacao);
    }

    /**
     * Overlay do NÍVEL 3 (obrigação) sobre uma config-base JÁ resolvida (caso). Campo a campo: um override
     * preenchido vence, `null` herda. É o que o `EncargosVivos` aplica por obrigação, sem re-navegar a
     * cascata (a base do caso é resolvida 1× pelo chamador). Honorários agora têm override próprio
     * (`taxa_honorarios_bp`, supersede D2): antes esta linha era fixa em `$base->taxaHonorariosBp`.
     */
    public function aplicarObrigacao(ConfigEncargos $base, Obrigacao $obrigacao): ConfigEncargos
    {
        return new ConfigEncargos(
            taxaJurosMensalBp: $obrigacao->getTaxaJurosMensalBp() ?? $base->taxaJurosMensalBp,
            regimeJuros: $obrigacao->getRegimeJuros() ?? $base->regimeJuros,
            taxaMultaBp: $obrigacao->getTaxaMultaBp() ?? $base->taxaMultaBp,
            baseMulta: $obrigacao->getBaseMulta() ?? $base->baseMulta,
            taxaCorrecaoBp: $obrigacao->getTaxaCorrecaoBp() ?? $base->taxaCorrecaoBp,
            baseCorrecao: $obrigacao->getBaseCorrecao() ?? $base->baseCorrecao,
            taxaHonorariosBp: $obrigacao->getTaxaHonorariosBp() ?? $base->taxaHonorariosBp,
            baseHonorarios: $obrigacao->getBaseHonorarios() ?? $base->baseHonorarios,
            carenciaHonorariosDias: $obrigacao->getCarenciaHonorariosDias() ?? $base->carenciaHonorariosDias,
            toleranciaJurosMultaDias: $obrigacao->getToleranciaJurosMultaDias() ?? $base->toleranciaJurosMultaDias,
        );
    }

    /**
     * Configuração efetiva de um caso: DELEGA integralmente ao objeto (spec #9-T1 §3.1/§3.2) — o
     * caso deixou de participar da cascata. Sem objeto (órfão), degrada para `neutra()`, o mesmo
     * fallback seguro de sempre. Mantido como método próprio (em vez de inlinar nos chamadores)
     * porque toda a produção já chama `resolverDoCaso` — o ponto de entrada não muda, só o que ele
     * lê por baixo.
     */
    public function resolverDoCaso(CasoCobranca $caso): ConfigEncargos
    {
        return $caso->getObjeto() !== null
            ? $this->resolverDoObjeto($caso->getObjeto())
            : ConfigEncargos::neutra();
    }

    /**
     * Configuração efetiva de um objeto: parte da Carteira e aplica os overrides do PRÓPRIO objeto
     * (spec #9-T1 §3.1) — o NÍVEL 2 (o "meio") da cascata `Carteira → Objeto → Obrigação`. Objeto
     * sem carteira (órfão) degrada para `neutra()`. Honorários cascateiam AO VIVO como qualquer
     * outro campo: `taxaHonorariosBp` do objeto vence; nulo herda o bp já resolvido da carteira
     * (`resolverDaCarteira`, que converte forma+percentual uma única vez).
     */
    public function resolverDoObjeto(ObjetoCobranca $objeto): ConfigEncargos
    {
        $carteira = $objeto->getCarteira();
        $herdada = $carteira === null ? ConfigEncargos::neutra() : $this->resolverDaCarteira($carteira);

        return new ConfigEncargos(
            taxaJurosMensalBp: $objeto->getTaxaJurosMensalBp() ?? $herdada->taxaJurosMensalBp,
            regimeJuros: $objeto->getRegimeJuros() ?? $herdada->regimeJuros,
            taxaMultaBp: $objeto->getTaxaMultaBp() ?? $herdada->taxaMultaBp,
            baseMulta: $objeto->getBaseMulta() ?? $herdada->baseMulta,
            taxaCorrecaoBp: $objeto->getTaxaCorrecaoBp() ?? $herdada->taxaCorrecaoBp,
            baseCorrecao: $objeto->getBaseCorrecao() ?? $herdada->baseCorrecao,
            taxaHonorariosBp: $objeto->getTaxaHonorariosBp() ?? $herdada->taxaHonorariosBp,
            baseHonorarios: $objeto->getBaseHonorarios() ?? $herdada->baseHonorarios,
            carenciaHonorariosDias: $objeto->getCarenciaHonorariosDias() ?? $herdada->carenciaHonorariosDias,
            toleranciaJurosMultaDias: $objeto->getToleranciaJurosMultaDias() ?? $herdada->toleranciaJurosMultaDias,
        );
    }

    /** Fundo da cascata: os campos da carteira são NOT NULL, então não há mais nada a herdar. */
    public function resolverDaCarteira(Carteira $carteira): ConfigEncargos
    {
        return new ConfigEncargos(
            taxaJurosMensalBp: $carteira->getTaxaJurosMensalBp(),
            regimeJuros: $carteira->getRegimeJuros(),
            taxaMultaBp: $carteira->getTaxaMultaBp(),
            baseMulta: $carteira->getBaseMulta(),
            taxaCorrecaoBp: $carteira->getTaxaCorrecaoBp(),
            baseCorrecao: $carteira->getBaseCorrecao(),
            taxaHonorariosBp: $this->basisPointsDeHonorarios(
                $carteira->getFormaHonorarios(),
                $carteira->getPercentualHonorarios(),
            ),
            baseHonorarios: $carteira->getBaseHonorarios(),
            // D3: sem carência própria, vale a tolerância de atraso já configurada na carteira.
            carenciaHonorariosDias: $carteira->getCarenciaHonorariosDias() ?? $carteira->getToleranciaAtrasoDias(),
            toleranciaJurosMultaDias: $carteira->getToleranciaJurosMultaDias(),
        );
    }

    /**
     * Percentual decimal do banco ("20.00") em basis points (2000). Zero quando a forma não usa
     * percentual ou quando não há percentual configurado. É a ÚNICA conversão via float de todo o
     * caminho — e é de CONFIGURAÇÃO, não de dinheiro: acontece uma vez, na borda, e daqui para
     * frente tudo é inteiro. Espelha `CalculadoraHonorarios::basisPoints()` de propósito, para as
     * duas calculadoras nunca divergirem na alíquota.
     */
    private function basisPointsDeHonorarios(FormaHonorarios $forma, ?string $percentual): int
    {
        if ($forma->exigePercentual() === false) {
            return 0;
        }

        if ($percentual === null) {
            return 0;
        }

        return (int) round(((float) $percentual) * 100);
    }
}
