<?php

namespace App\Controller\Trait;

use App\Entity\Permission\AccessRequest;
use App\Service\PermissionChecker;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Centraliza a verificação de acesso a recursos (cliente, pasta, processo)
 * e o redirecionamento para o modal de solicitação quando a permissão é negada.
 */
trait ResourceAccessTrait
{
    /**
     * Verifica se o usuário tem a permissão solicitada sobre o recurso.
     * Caso não tenha, retorna um RedirectResponse para o index com os params
     * que acionam o modal de solicitação de acesso. Caso tenha, retorna null.
     *
     * Uso:
     *   if ($redirect = $this->denyResourceAccessUnlessGranted(...)) {
     *       return $redirect;
     *   }
     */
    private function denyResourceAccessUnlessGranted(
        PermissionChecker $checker,
        string $resourceType,
        int $resourceId,
        string $action,
        string $indexRoute,
        string $resourceLabel = '',
    ): ?RedirectResponse {
        $user = $this->getUser();

        if ($checker->canAccessResource($user, $resourceType, $resourceId, $action)) {
            return null;
        }

        return $this->redirectToRoute($indexRoute, [
            'requestAccess' => 1,
            'resourceType'  => $resourceType,
            'resourceId'    => $resourceId,
            'action'        => $action,
            'resourceLabel' => $resourceLabel,
        ]);
    }
}
