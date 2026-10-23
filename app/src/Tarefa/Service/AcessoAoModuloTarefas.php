<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;

/**
 * O módulo das metas (`tarefas`), num lugar só: a página da meta (`TarefaController::assertAccess`) e o desfazer da
 * auditoria (`GuardaDaTelaNoDesfazer`) perguntam aqui, para os dois seguirem qualquer mudança da regra.
 */
final class AcessoAoModuloTarefas
{
    public const MODULO = 'tarefas';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function pode(User $pessoa, Tenant $escritorio): bool
    {
        return $this->permissionChecker->canAccessModule($pessoa, $escritorio, self::MODULO);
    }
}
