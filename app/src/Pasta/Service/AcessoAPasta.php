<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Pasta\Entity\Pasta;
use App\Service\PermissionChecker;

/**
 * Quem vê a pasta (o "cadeado" da pasta): a mesma pergunta que o Registro faz antes de avisar alguém
 * (`ResolucaoDeMencoesDoRegistro`), num lugar só.
 *
 * Provisório; a frente pasta-cadeado (b5) substitui pelo serviço com nivel/podeVer/podeEditar, mesma assinatura.
 */
final class AcessoAPasta
{
    public function __construct(
        private readonly PermissionChecker $permissoes,
    ) {
    }

    public function podeVer(User $pessoa, Pasta $pasta): bool
    {
        return $this->permissoes->canAccessResource(
            $pessoa,
            $pasta->getTenant(),
            AccessRequest::RESOURCE_PASTA,
            (int) $pasta->getId(),
            AccessRequest::ACTION_VIEW,
        );
    }
}
