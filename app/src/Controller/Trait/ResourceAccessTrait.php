<?php

namespace App\Controller\Trait;

use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use Symfony\Component\HttpFoundation\RedirectResponse;

trait ResourceAccessTrait
{
    private function denyResourceAccessUnlessGranted(
        PermissionChecker $checker,
        ?Tenant $tenant,
        string $resourceType,
        int $resourceId,
        string $action,
        string $indexRoute,
        string $resourceLabel = '',
    ): ?RedirectResponse {
        $user = $this->getUser();

        if ($checker->canAccessResource($user, $tenant, $resourceType, $resourceId, $action)) {
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
