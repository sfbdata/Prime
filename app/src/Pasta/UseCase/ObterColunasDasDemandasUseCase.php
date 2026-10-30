<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Exception\ColunasDoExpedienteInvalidasException;
use App\Expediente\UseCase\GravarColunasDoExpedienteUseCase;

/**
 * As colunas das Minhas Demandas que o usuário LOGADO guardou neste escritório ({@see GravarColunasDasDemandasUseCase}).
 * Sem ajuste, ou com um valor que não passaria na gravação, `null`: a tela usa o padrão do desenho 15.
 */
final class ObterColunasDasDemandasUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    /** @return array<string, bool>|null */
    public function executar(Tenant $tenant, User $usuario): ?array
    {
        $chave = GravarColunasDasDemandasUseCase::CHAVE;
        $valor = $this->preferencias->valoresDoUsuario($tenant, $usuario, [$chave])[$chave] ?? null;
        if ($valor === null) {
            return null;
        }

        try {
            return GravarColunasDoExpedienteUseCase::exigirMapaValido($valor);
        } catch (ColunasDoExpedienteInvalidasException) {
            return null;
        }
    }
}
