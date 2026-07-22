<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\EventoHistorico;

/**
 * Um evento na lista do detalhe da pessoa (hora · tipo · objeto · resumo). Diferente do
 * `EventoHistoricoOutput` (timeline de UM caso, onde o objeto é óbvio pelo contexto), aqui a lista
 * atravessa vários casos — então o OBJETO precisa vir junto, com id para linkar a tela dele.
 *
 * O payload `dados` continua fora: o desfecho já sai agregado nas pastilhas.
 */
final class EventoAtividadeOutput
{
    public function __construct(
        public readonly \DateTimeImmutable $ocorridoEm,
        public readonly string $tipoLabel,
        public readonly ?int $objetoId,
        public readonly string $objetoIdentificacao,
        public readonly string $descricao,
    ) {
    }

    public static function fromEntity(EventoHistorico $evento): self
    {
        $objeto = $evento->getCaso()?->getObjeto();

        return new self(
            ocorridoEm: $evento->getOcorridoEm(),
            tipoLabel: $evento->getTipo()->label(),
            objetoId: $objeto?->getId(),
            objetoIdentificacao: $objeto?->getIdentificacao() ?? '—',
            descricao: $evento->getDescricao(),
        );
    }
}
