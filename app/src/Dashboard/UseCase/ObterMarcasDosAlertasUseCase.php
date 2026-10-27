<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\Preferencia\MarcasDosAlertas;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * As marcas dos alertas da Central de Inteligência do usuário LOGADO no escritório da sessão
 * (não existe leitura da marca de outra pessoa). Sem marca gravada, mapa vazio.
 */
final class ObterMarcasDosAlertasUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $repository,
    ) {
    }

    /** @return array<string, array{status: ?string, feedback: ?string}> id do alerta => marca */
    public function executar(Tenant $tenant, User $usuario): array
    {
        $gravado = $this->repository->valoresDoUsuario($tenant, $usuario, [MarcasDosAlertas::CHAVE]);

        return MarcasDosAlertas::lerOuVazio($gravado[MarcasDosAlertas::CHAVE] ?? null);
    }
}
