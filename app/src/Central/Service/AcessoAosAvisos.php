<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;

/**
 * Rechecagem de acesso na LEITURA (F1, regra 4): o sino grava o aviso com o acesso do momento, mas a pessoa pode ter
 * perdido a pasta depois. Aviso ligado a uma pasta (pela meta ou pela URL `/pasta/{id}`) só sai se ela ainda vê a
 * pasta, ou se é responsável pela meta. Uma consulta de permissão por pasta: quem chama guarda o `$memoria` só durante
 * aquela leitura (o serviço é compartilhado entre pedidos; um cache aqui sobreviveria a uma revogação).
 */
final class AcessoAosAvisos
{
    public function __construct(private readonly PermissionChecker $permissoes)
    {
    }

    public function pastaDe(Notificacao $aviso): ?int
    {
        $pasta = $aviso->getTarefa()?->getPasta()->getId();
        if ($pasta !== null) {
            return (int) $pasta;
        }

        return preg_match('#^/pasta/(\d+)#', (string) $aviso->getUrl(), $m) === 1 ? (int) $m[1] : null;
    }

    /** @param array<int, bool> $memoria */
    public function podeVer(Notificacao $aviso, User $eu, Tenant $tenant, array &$memoria): bool
    {
        $tarefa = $aviso->getTarefa();
        if ($tarefa !== null && $tarefa->getResponsaveis()->exists(static fn ($i, User $u): bool => $u->getId() === $eu->getId())) {
            return true;
        }

        return $this->podeVerPasta($this->pastaDe($aviso), $eu, $tenant, $memoria);
    }

    /** @param array<int, bool> $memoria */
    public function podeVerPasta(?int $pasta, User $eu, Tenant $tenant, array &$memoria): bool
    {
        if ($pasta === null) {
            return true;
        }

        return $memoria[$pasta] ??= $this->permissoes->canAccessResource($eu, $tenant, AccessRequest::RESOURCE_PASTA, $pasta, AccessRequest::ACTION_VIEW);
    }
}
