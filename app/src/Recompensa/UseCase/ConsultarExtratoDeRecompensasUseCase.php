<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Recompensa\DTO\LancamentoDoExtratoOutput;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;

/**
 * Lê o extrato do bloco "Extrato de pagamentos e liberações" do /master (desenho 05 Perfis 1.2 VS 25.10.26, L329-337).
 *
 * Quem dispara: o Master (o controller exige ROLE_SUPER_ADMIN). Dado de plataforma, sem tenant.
 * Fluxo principal: na tela, os 60 mais recentes (com ou sem filtro por indicador); na exportação, todos. Traz o nome e
 * o e-mail do INDICADOR, nunca dado do indicado.
 */
final class ConsultarExtratoDeRecompensasUseCase
{
    public const LIMITE_DA_TELA = 60;

    public function __construct(
        private readonly LancamentoDeRecompensaRepository $lancamentos,
    ) {
    }

    /** @return list<LancamentoDoExtratoOutput> */
    public function recentes(?int $userId = null): array
    {
        return $this->mapear($this->lancamentos->doExtrato($userId, self::LIMITE_DA_TELA));
    }

    /** @return list<LancamentoDoExtratoOutput> */
    public function todos(?int $userId = null): array
    {
        return $this->mapear($this->lancamentos->doExtrato($userId, null));
    }

    /**
     * @param list<\App\Recompensa\Entity\LancamentoDeRecompensa> $lancamentos
     *
     * @return list<LancamentoDoExtratoOutput>
     */
    private function mapear(array $lancamentos): array
    {
        return array_map(LancamentoDoExtratoOutput::da(...), $lancamentos);
    }
}
