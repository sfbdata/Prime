<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;

/**
 * Resolve a configuração EFETIVA de encargos percorrendo a cascata de três níveis
 * Carteira → Objeto/Caso → Obrigação (spec "encargos configuráveis em cascata" §4.1).
 *
 * A resolução é CAMPO A CAMPO, não bloco a bloco: um override que preenche apenas a taxa de juros
 * herda todo o resto do nível de cima. Bloco a bloco seria uma armadilha — sobrepor a taxa de juros
 * zeraria silenciosamente multa e honorários.
 *
 * Serviço puro e stateless (sem I/O): recebe entidades já carregadas e devolve um DTO imutável.
 *
 * Duas amarrações com o que já existia, para não criar modelo paralelo:
 *  - a TAXA de honorários não é coluna nova (decisão D2): deriva de `formaHonorarios` +
 *    `percentualHonorarios`, exatamente como `CalculadoraHonorarios::basisPoints()` já fazia;
 *  - `carenciaHonorariosDias` nulo cai para o `toleranciaAtrasoDias` da carteira (decisão D3),
 *    porque os dados reais mostram que aquela tolerância de ~30 dias já configurada é, na prática,
 *    carência de honorários.
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
     * Configuração efetiva de um caso: parte da Carteira e aplica os overrides do caso. A taxa de
     * honorários vem do SNAPSHOT do próprio caso (§18.2/§18.3) — nunca da carteira atual, senão
     * mudar o padrão da carteira recalcularia casos antigos.
     */
    public function resolverDoCaso(CasoCobranca $caso): ConfigEncargos
    {
        $carteira = $caso->getObjeto()?->getCarteira();
        $herdada = $carteira === null ? ConfigEncargos::neutra() : $this->resolverDaCarteira($carteira);

        return new ConfigEncargos(
            taxaJurosMensalBp: $caso->getTaxaJurosMensalBp() ?? $herdada->taxaJurosMensalBp,
            regimeJuros: $caso->getRegimeJuros() ?? $herdada->regimeJuros,
            taxaMultaBp: $caso->getTaxaMultaBp() ?? $herdada->taxaMultaBp,
            baseMulta: $caso->getBaseMulta() ?? $herdada->baseMulta,
            taxaCorrecaoBp: $caso->getTaxaCorrecaoBp() ?? $herdada->taxaCorrecaoBp,
            baseCorrecao: $caso->getBaseCorrecao() ?? $herdada->baseCorrecao,
            taxaHonorariosBp: $this->basisPointsDeHonorarios(
                $caso->getFormaHonorarios(),
                $caso->getPercentualHonorarios(),
            ),
            baseHonorarios: $caso->getBaseHonorarios() ?? $herdada->baseHonorarios,
            carenciaHonorariosDias: $caso->getCarenciaHonorariosDias() ?? $herdada->carenciaHonorariosDias,
            toleranciaJurosMultaDias: $caso->getToleranciaJurosMultaDias() ?? $herdada->toleranciaJurosMultaDias,
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
