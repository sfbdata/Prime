<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;

/**
 * O estado das três chaves do "Envio automático" de um cliente: o da linha gravada ou, sem linha, o
 * padrão (D1: todas desligadas).
 */
final readonly class ChavesDoEnvioAutomatico
{
    public function __construct(
        public bool $novasMovimentacoes = ChaveDoEnvioAutomatico::PADRAO,
        public bool $audienciasIntimacoes = ChaveDoEnvioAutomatico::PADRAO,
        public bool $resumoSemanal = ChaveDoEnvioAutomatico::PADRAO,
    ) {
    }

    public static function da(?EnvioAutomaticoDoCliente $linha): self
    {
        $valor = static fn (ChaveDoEnvioAutomatico $chave): bool => $linha?->ligada($chave) ?? ChaveDoEnvioAutomatico::PADRAO;

        return new self(
            $valor(ChaveDoEnvioAutomatico::NovasMovimentacoes),
            $valor(ChaveDoEnvioAutomatico::AudienciasIntimacoes),
            $valor(ChaveDoEnvioAutomatico::ResumoSemanal),
        );
    }

    public function ligada(ChaveDoEnvioAutomatico $chave): bool
    {
        return match ($chave) {
            ChaveDoEnvioAutomatico::NovasMovimentacoes => $this->novasMovimentacoes,
            ChaveDoEnvioAutomatico::AudienciasIntimacoes => $this->audienciasIntimacoes,
            ChaveDoEnvioAutomatico::ResumoSemanal => $this->resumoSemanal,
        };
    }

    /** Alguma das chaves que disparam na captação (novas movimentações ou audiências e intimações). */
    public function avisaNaCaptacao(): bool
    {
        return $this->novasMovimentacoes || $this->audienciasIntimacoes;
    }
}
