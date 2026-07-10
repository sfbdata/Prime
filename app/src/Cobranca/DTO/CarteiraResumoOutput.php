<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Carteira;

/**
 * Leitura resumida de uma Carteira para a lista de carteiras (Etapa 8). Nunca passa a entidade
 * Doctrine crua ao Twig (regra templates/CLAUDE.md). `clienteNome` vem do credor (invariável 4).
 */
final class CarteiraResumoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $clienteNome,
        public readonly string $modoLabel,
        public readonly string $formaHonorariosLabel,
        public readonly ?string $percentualHonorarios,
        public readonly ?\DateTimeImmutable $atualizadoEm,
    ) {
    }

    public static function fromEntity(Carteira $c): self
    {
        $cliente = $c->getCliente();

        return new self(
            id: $c->getId() ?? 0,
            nome: $c->getNome(),
            clienteNome: $cliente !== null ? $cliente->getNomeExibicao() : '—',
            modoLabel: $c->getModo()->label(),
            formaHonorariosLabel: $c->getFormaHonorarios()->label(),
            percentualHonorarios: $c->getPercentualHonorarios(),
            atualizadoEm: $c->getAtualizadoEm() ?? $c->getCriadoEm(),
        );
    }
}
