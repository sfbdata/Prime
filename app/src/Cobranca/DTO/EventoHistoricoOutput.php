<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\EventoHistorico;
use App\Entity\Auth\User;

/**
 * Leitura de um evento do Histórico operacional do Caso (SPEC §13) para a timeline do detalhe
 * (Etapa 8). Histórico operacional ≠ auditoria técnica (invariável 26); este é o log de domínio.
 * O payload `dados` NÃO é exposto na timeline (detalhe técnico); só tipo/descrição/quem/quando.
 */
final class EventoHistoricoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $tipoValue,
        public readonly string $tipoLabel,
        /**
         * Família do evento para a timeline do objeto (`contatos`/`dinheiro`/`obrigacoes`/`anotacoes`/
         * `cadastro`) — dá o chip, a cor do ponto e o recorte dos filtros. Vem de
         * `TipoEventoHistorico::familia()`; o Twig não classifica nada.
         */
        public readonly string $familia,
        /** Rótulo do chip da família (`Contato`, `Dinheiro`, `Obrigação`, `Anotação`, `Cadastro`). */
        public readonly string $familiaLabel,
        public readonly \DateTimeImmutable $ocorridoEm,
        public readonly ?string $usuarioNome,
        public readonly string $descricao,
        /** Preenchido quando o autor reescreveu o texto — a timeline exibe "(editado)". */
        public readonly ?\DateTimeImmutable $editadoEm = null,
        /**
         * Se ESTE usuário pode corrigir/apagar esta linha agora (anotação própria, dentro das 48h).
         * Vem decidido do servidor pela MESMA regra que o UseCase aplica
         * (`EventoHistorico::podeSerEditadaPor`) — o template não recalcula prazo nem compara autoria,
         * senão a tela e o servidor poderiam discordar.
         */
        public readonly bool $editavel = false,
    ) {
    }

    public static function fromEntity(EventoHistorico $e, ?User $usuarioAtual = null, ?\DateTimeImmutable $agora = null): self
    {
        $usuario = $e->getUsuario();

        return new self(
            id: $e->getId() ?? 0,
            tipoValue: $e->getTipo()->value,
            tipoLabel: $e->getTipo()->label(),
            familia: $e->getTipo()->familia(),
            familiaLabel: $e->getTipo()->familiaLabel(),
            ocorridoEm: $e->getOcorridoEm(),
            usuarioNome: $usuario?->getFullName() ?? $usuario?->getEmail(),
            descricao: $e->getDescricao(),
            editadoEm: $e->getEditadoEm(),
            editavel: $agora !== null && $e->podeSerEditadaPor($usuarioAtual, $agora),
        );
    }
}
