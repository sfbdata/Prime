<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Pasta\DTO\FiltroDaTimelineInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\MontadorDaTimelineInteligente;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Timeline inteligente da pasta (menu ⋮ → "Timeline inteligente", atalho T; desenho 1.2.3) —
 * os eventos em JSON, por REGRAS fixas, sem IA (D-IA).
 *
 * Só leitura. Guarda em duas camadas explícitas, a mesma da tela da pasta:
 *   1. a pasta é do escritório da sessão — o resolver busca por PK e o TenantFilter não se aplica
 *      a `find()`, então a conferência de dono não fica implícita (404, sem confirmar que existe);
 *   2. o usuário pode VER esta pasta (`resources.pasta.view`) — 403.
 * Cada fonte, depois, é consultada presa ao escritório E à pasta (ver o montador).
 *
 * Pasta excluída (lápide) continua legível: é GET, e o histórico é justamente o que se quer ler.
 */
#[Route('/pasta')]
final class PastaTimelineController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly MontadorDaTimelineInteligente $montador,
    ) {
    }

    #[Route(
        '/{id}/timeline',
        name: 'pasta_timeline',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function eventos(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            return $this->json(['erro' => 'Sem permissão para ver esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        $timeline = $this->montador->montar($pasta, $tenant, $usuario, FiltroDaTimelineInput::daRequisicao($request));

        $resposta = $this->json($timeline->paraArray());
        $resposta->headers->set('Cache-Control', 'no-store, private');

        return $resposta;
    }
}
