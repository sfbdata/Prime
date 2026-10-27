<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Repository\ApoioRepository;
use App\Contribua\UseCase\RegistrarApoioUseCase as Reais;

/**
 * História: o banco confirmou um apoio da conta. Na próxima vez que alguém da conta abrir a página
 * Contribua, aparece o prêmio do apoiador (desenho 08, L192-203), uma vez por apoio confirmado. Quem vê
 * clica em "Aproveitar agora" ou "Ver propostas de correspondência", e o prêmio daquele apoio não volta.
 */
final class PremioDoApoiadorUseCase
{
    public function __construct(
        private readonly ApoioRepository $apoios,
    ) {
    }

    /** @return array{id: int, valor: string}|null */
    public function pendente(int $tenantId): ?array
    {
        $apoio = $this->apoios->comPremioPendente($tenantId);

        return null === $apoio ? null : ['id' => (int) $apoio->getId(), 'valor' => Reais::reais($apoio->getValorCentavos())];
    }

    public function marcarVisto(int $apoioId, int $tenantId): void
    {
        $apoio = $this->apoios->doEscritorio($apoioId, $tenantId);
        if (null === $apoio) {
            return;
        }
        $apoio->marcarPremioVisto(new \DateTimeImmutable());
        $this->apoios->salvar($apoio, true);
    }
}
