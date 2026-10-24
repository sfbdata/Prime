<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\DestaqueDeComentarioRecusadoException;
use App\Pasta\UseCase\DestacarComentarioUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Destacar comentário" (spec pasta-destacar-comentario.md): só JSON; a tela é o `pasta-destaque.js`. Pasta de outro
 * escritório ou comentário de outra pasta dá 404 (não revela que existe); sem edição, 403; cor ou aba inválidas, 422.
 * CSRF `pasta_comentario_destaque_{pastaId}` no campo `_token`.
 */
final class PastaComentarioDestaqueController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/pasta/{id}/comentario/destaque', name: 'pasta_comentario_destaque', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function destacar(Pasta $pasta, Request $request, DestacarComentarioUseCase $destacar): JsonResponse
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $usuario = $this->getUser();
        if ($tenant === null || !$usuario instanceof User || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid('pasta_comentario_destaque_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $resultado = $destacar->executar(
                $pasta,
                (string) $request->request->get('aba'),
                (int) $request->request->get('comentario'),
                (string) $request->request->get('cor'),
                $usuario,
                $tenant,
            );
        } catch (DestaqueDeComentarioRecusadoException $e) {
            return $this->json(['erro' => $e->getMessage()], $e->tipo());
        }

        return $this->json($resultado->paraJson());
    }
}
