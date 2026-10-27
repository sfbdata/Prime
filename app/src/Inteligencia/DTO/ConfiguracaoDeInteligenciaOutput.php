<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;

/**
 * O painel /admin/inteligencia: a configuração do escritório (ou os defaults, se ainda não há
 * linha) mais o estado da plataforma (provedor, flag `IA_HABILITADA` e o uso do dia/mês) e o consumo do mês:
 * gasto estimado em centavos de US$, o orçamento que o Master definiu e o modo de cobrança.
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
        public ModoDeCobrancaDaIa $modoDeCobranca = ModoDeCobrancaDaIa::Incluso,
        public ?int $orcamentoMensalCentavos = null,
        public int $gastoMesCentavos = 0,
        /** O aceite já cobre a leitura de documentos (PR F)? Falso = a tela pede a renovação. */
        public bool $aceiteCobreDocumentos = false,
    ) {
    }

    public static function de(
        ?ConfiguracaoDeInteligencia $configuracao,
        string $provedorNome,
        bool $provedorConfigurado,
        bool $plataformaHabilitada,
        int $usoHoje,
        int $usoMes,
        int $gastoMesCentavos = 0,
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
            modoDeCobranca: $configuracao?->getModoDeCobranca() ?? ModoDeCobrancaDaIa::Incluso,
            orcamentoMensalCentavos: $configuracao?->getOrcamentoMensalCentavos(),
            gastoMesCentavos: $gastoMesCentavos,
            aceiteCobreDocumentos: $configuracao?->aceiteCobreDocumentos() ?? false,
        );
    }

    /** Gasto estimado do mês em dólares, com duas casas e vírgula ("1,25"), para a tela. */
    public function gastoMesEmDolares(): string
    {
        return self::dolares($this->gastoMesCentavos);
    }

    /** Orçamento mensal em dólares ("12,50"), ou nulo sem orçamento. */
    public function orcamentoEmDolares(): ?string
    {
        return $this->orcamentoMensalCentavos === null ? null : self::dolares($this->orcamentoMensalCentavos);
    }

    private static function dolares(int $centavos): string
    {
        return number_format($centavos / 100, 2, ',', '.');
    }

    /** A cadeia inteira está ligada (plataforma + provedor + escritório)? */
    public function operacional(): bool
    {
        return $this->plataformaHabilitada && $this->provedorConfigurado && $this->habilitada;
    }
}
