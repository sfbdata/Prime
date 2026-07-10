<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\CasoCobranca;

/**
 * Um Caso e seus alertas operacionais, para a Central de Alertas global (Etapa 9). Embrulha os
 * `AlertaCobranca` (que sozinhos não carregam o caso de origem) com os dados mínimos de exibição e o
 * link para o detalhe. Somente casos COM alerta são materializados — o UseCase filtra os vazios.
 *
 * @see \App\Cobranca\DTO\CentralAlertasOutput
 */
final class CasoComAlertasOutput
{
    /**
     * @param AlertaCobranca[] $alertas
     */
    public function __construct(
        public readonly int $casoId,
        public readonly string $objetoIdentificacao,
        public readonly int $carteiraId,
        public readonly string $carteiraNome,
        public readonly string $pessoaCobradaNome,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly array $alertas,
    ) {
    }

    /**
     * @param AlertaCobranca[] $alertas
     */
    public static function fromEntity(CasoCobranca $c, array $alertas): self
    {
        $objeto = $c->getObjeto();
        $carteira = $objeto?->getCarteira();
        $status = $c->getStatus();

        return new self(
            casoId: $c->getId() ?? 0,
            objetoIdentificacao: $objeto?->getIdentificacao() ?? '—',
            carteiraId: $carteira?->getId() ?? 0,
            carteiraNome: $carteira?->getNome() ?? '—',
            pessoaCobradaNome: $c->getPessoaCobradaAtual()?->getNome() ?? '—',
            statusLabel: $status->label(),
            statusBadgeClass: $status->badgeClass(),
            alertas: $alertas,
        );
    }
}
