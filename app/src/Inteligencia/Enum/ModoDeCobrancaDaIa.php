<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Como a BlueJus IA é cobrada de UM escritório, escolhido pelo Master (spec ia-anthropic-ligar §10, Q4). O orçamento
 * mensal em dólares vale na {@see \App\Inteligencia\Service\DisponibilidadeDeInteligencia} conforme o modo
 * ({@see travaNoOrcamento()}).
 */
enum ModoDeCobrancaDaIa: string
{
    case Incluso = 'incluso';
    case Adicional = 'adicional';
    case LimiteGratis = 'limite_gratis';

    public function rotulo(): string
    {
        return match ($this) {
            self::Incluso => 'Incluso no plano',
            self::Adicional => 'Adicional pago',
            self::LimiteGratis => 'Limite grátis, depois cobra',
        };
    }

    /**
     * O orçamento mensal BLOQUEIA a IA ao ser atingido? Em `incluso` e `adicional`, sim: é o teto do escritório. Em
     * `limite_gratis`, não: o orçamento é a parte grátis, e passar dele só aparece no painel do Master como "acima
     * do grátis". Padrão adotado em 24/10/2026; o dono pode rever.
     */
    public function travaNoOrcamento(): bool
    {
        return $this !== self::LimiteGratis;
    }

    /** Neste modo, com este orçamento (nulo = sem orçamento), o gasto do mês já bloqueia a IA? No limite exato, sim. */
    public function orcamentoAtingido(?int $orcamentoCentavos, int $gastoCentavos): bool
    {
        return $this->travaNoOrcamento() && $orcamentoCentavos !== null && $gastoCentavos >= $orcamentoCentavos;
    }

    /** Em `limite_gratis`, o gasto do mês passou da parte grátis (o selo "acima do grátis" do Master). */
    public function acimaDoGratis(?int $orcamentoCentavos, int $gastoCentavos): bool
    {
        return $this === self::LimiteGratis && $orcamentoCentavos !== null && $gastoCentavos > $orcamentoCentavos;
    }
}
