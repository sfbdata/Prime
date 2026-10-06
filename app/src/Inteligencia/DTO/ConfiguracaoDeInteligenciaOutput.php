<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;

/**
 * O painel /admin/inteligencia: a configuração do escritório (ou os defaults, se ainda não há
 * linha) mais o estado da plataforma — provedor, flag `IA_HABILITADA` e o uso do dia/mês.
 */
final readonly class ConfiguracaoDeInteligenciaOutput
{
    public function __construct(
        public bool $existe,
        public bool $habilitada,
        public int $limiteDiario,
        public int $limiteMensal,
        public bool $mascararDadosPessoais,
        public ?\DateTimeImmutable $consentimentoEnvioExternoEm,
        public ?string $consentimentoPorNome,
        public ?\DateTimeImmutable $atualizadaEm,
        public string $provedorNome,
        public bool $provedorConfigurado,
        public bool $plataformaHabilitada,
        public int $usoHoje,
        public int $usoMes,
    ) {
    }

    public static function de(
        ?ConfiguracaoDeInteligencia $configuracao,
        string $provedorNome,
        bool $provedorConfigurado,
        bool $plataformaHabilitada,
        int $usoHoje,
        int $usoMes,
    ): self {
        return new self(
            existe: $configuracao !== null,
            habilitada: $configuracao?->isHabilitada() ?? false,
            limiteDiario: $configuracao?->getLimiteDiario() ?? ConfiguracaoDeInteligencia::LIMITE_DIARIO_PADRAO,
            limiteMensal: $configuracao?->getLimiteMensal() ?? ConfiguracaoDeInteligencia::LIMITE_MENSAL_PADRAO,
            mascararDadosPessoais: $configuracao?->isMascararDadosPessoais() ?? true,
            consentimentoEnvioExternoEm: $configuracao?->getConsentimentoEnvioExternoEm(),
            consentimentoPorNome: $configuracao?->getConsentimentoPor()?->getFullName(),
            atualizadaEm: $configuracao?->getAtualizadaEm(),
            provedorNome: $provedorNome,
            provedorConfigurado: $provedorConfigurado,
            plataformaHabilitada: $plataformaHabilitada,
            usoHoje: $usoHoje,
            usoMes: $usoMes,
        );
    }

    /** A cadeia inteira está ligada (plataforma + provedor + escritório)? */
    public function operacional(): bool
    {
        return $this->plataformaHabilitada && $this->provedorConfigurado && $this->habilitada;
    }
}
