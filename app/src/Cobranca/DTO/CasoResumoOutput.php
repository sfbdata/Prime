<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusCaso;

/**
 * Leitura resumida de um Caso para a lista de casos e para a visão da carteira (Etapa 8).
 * Saldo exigível/vencido é DERIVADO (`CalculadoraSaldo`, invariável 20) e passado pronto (centavos int)
 * — a lista não recalcula. `prontoParaEncerrar` é indicador derivado (saldo exigível 0 e não encerrado),
 * NÃO um 4º estado (SPEC §17). `temVencido` dá o realce de linha no Twig. `temDocumentos` é o "grampo"
 * (Ajuste #6): verdadeiro se o OBJETO do caso tem algum documento (num caso ou num acordo); resolvido
 * fora (agregação em lote, como os saldos) — default `false` para chamadores que não o calculam.
 */
final class CasoResumoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly int $objetoId,
        public readonly string $objetoIdentificacao,
        public readonly ?string $objetoDescricao,
        public readonly int $carteiraId,
        public readonly string $carteiraNome,
        public readonly string $pessoaCobradaNome,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly bool $prontoParaEncerrar,
        public readonly int $saldoExigivel,
        public readonly int $saldoVencido,
        public readonly bool $temVencido,
        public readonly ?\DateTimeImmutable $atualizadoEm,
        public readonly bool $temDocumentos = false,
    ) {
    }

    public static function fromEntity(
        CasoCobranca $c,
        int $saldoExigivel,
        int $saldoVencido,
        bool $temDocumentos = false,
    ): self {
        $objeto = $c->getObjeto();
        $carteira = $objeto?->getCarteira();
        $pessoa = $c->getPessoaCobradaAtual();
        $status = $c->getStatus();

        return new self(
            id: $c->getId() ?? 0,
            objetoId: $objeto?->getId() ?? 0,
            objetoIdentificacao: $objeto?->getIdentificacao() ?? '—',
            objetoDescricao: $objeto?->getDescricao(),
            carteiraId: $carteira?->getId() ?? 0,
            carteiraNome: $carteira?->getNome() ?? '—',
            pessoaCobradaNome: $pessoa?->getNome() ?? '—',
            statusLabel: $status->label(),
            statusBadgeClass: $status->badgeClass(),
            prontoParaEncerrar: $status !== StatusCaso::Encerrado && $saldoExigivel === 0,
            saldoExigivel: $saldoExigivel,
            saldoVencido: $saldoVencido,
            temVencido: $saldoVencido > 0,
            atualizadoEm: $c->getAtualizadoEm() ?? $c->getCriadoEm(),
            temDocumentos: $temDocumentos,
        );
    }
}
