<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\DTO\LeituraDoClienteOutput;
use App\Cliente\Entity\Cliente;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;

/**
 * Monta a {@see LeituraDoClienteOutput} de um cliente que JÁ passou pela guarda de quem chama: o
 * {@see \App\Cliente\UseCase\LerClienteUseCase} (ver o cliente) ou a edição de contato da janela (editar o cliente).
 * Não confere escritório nem permissão do cliente; nunca chame com um cliente que a pessoa não pode ver ou editar.
 *
 * Cada pasta da lista passa por `canAccessResource('pasta', view)`, a mesma regra que abre a pasta: o escritório
 * sozinho NÃO basta (quem tem acesso a uma pasta isolada não descobre, por aqui, as outras do mesmo cliente).
 * Sem sessão nem `TenantContext`: pessoa e escritório vêm por parâmetro.
 */
final class LeituraDoCliente
{
    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly PermissionChecker $permissoes,
        private readonly PendenciasDoCadastro $pendencias,
    ) {
    }

    public function montar(Cliente $cliente, User $quemOlha, Tenant $escritorio): LeituraDoClienteOutput
    {
        $visiveis = array_values(array_filter(
            $this->pastas->findByCliente($cliente, $escritorio),
            fn (Pasta $pasta): bool => $this->permissoes->canAccessResource(
                $quemOlha,
                $escritorio,
                AccessRequest::RESOURCE_PASTA,
                (int) $pasta->getId(),
                AccessRequest::ACTION_VIEW,
            ),
        ));

        return new LeituraDoClienteOutput(
            $cliente,
            $visiveis,
            $this->pendencias->porAbaVistoPor($cliente, $quemOlha, $escritorio),
            $this->permissoes->canAccessResource($quemOlha, $escritorio, AccessRequest::RESOURCE_CLIENTE, (int) $cliente->getId(), AccessRequest::ACTION_EDIT),
        );
    }
}
