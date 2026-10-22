<?php

declare(strict_types=1);

namespace App\Expediente\UseCase;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Exception\ColunasDoExpedienteInvalidasException;

/**
 * As colunas da Lista do Expediente que o usuário LOGADO guardou neste escritório (o
 * {@see GravarColunasDoExpedienteUseCase} grava). Quem chama passa usuário e escritório da sessão: não
 * existe leitura do ajuste de outra pessoa. Sem ajuste (ou com um valor que não passaria na gravação),
 * `null`: a tela usa o padrão.
 */
final class ObterColunasDoExpedienteUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    /** @return array<string, bool>|null */
    public function executar(Tenant $tenant, User $usuario): ?array
    {
        $chave = GravarColunasDoExpedienteUseCase::CHAVE;
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
