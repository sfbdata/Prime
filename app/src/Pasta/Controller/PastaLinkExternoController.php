<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\LinkExternoDoPushException;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Pasta\UseCase\GerarLinkExternoDoPushUseCase;
use App\Pasta\UseCase\ListarLinksExternosDoPushUseCase;
use App\Pasta\UseCase\RecusarRenovacaoDoLinkUseCase;
use App\Pasta\UseCase\RenovarLinkExternoDoPushUseCase;
use App\Pasta\UseCase\RevogarLinkExternoDoPushUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Link externo do Push Processual, lado do escritório (spec push-link-externo §3): listar, gerar,
 * revogar, renovar e recusar a renovação. Só JSON; a tela é o modal da aba Push.
 *
 * Guarda igual à do `PastaPushProcessualController`: a pasta é do escritório da sessão (404) e o
 * usuário pode VER esta pasta (403). O desenho manda que qualquer um que vê a aba gere e copie o
 * link. O link de cada rota por id é buscado JÁ restrito a esta pasta e a este escritório
 * (`findOneDaPasta`): link de outra pasta, mesmo do mesmo escritório, é 404.
 *
 * Escrita com CSRF `pasta_push_link_{pastaId}` no campo `_token`. A pasta excluída (lápide) recebe a
 * recusa do `PastaSomenteLeituraListener`, como todo POST nela.
 */
#[Route('/pasta/{id}/push/links', requirements: ['id' => '\d+'])]
final class PastaLinkExternoController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly LinkExternoDoPushRepository $links,
    ) {
    }

    #[Route('', name: 'pasta_push_links_listar', methods: ['GET'])]
    public function listar(Pasta $pasta, ListarLinksExternosDoPushUseCase $listar): JsonResponse
    {
        $recusa = $this->recusaDoGuarda($pasta);
        if ($recusa !== null) {
            return $recusa;
        }

        return $this->json($listar->executar($pasta)->paraJson());
    }

    #[Route('', name: 'pasta_push_links_gerar', methods: ['POST'])]
    public function gerar(Pasta $pasta, Request $request, GerarLinkExternoDoPushUseCase $gerar): JsonResponse
    {
        $recusa = $this->recusaDoGuarda($pasta, $request);
        if ($recusa !== null) {
            return $recusa;
        }

        try {
            $link = $gerar->executar(
                $pasta,
                $this->usuario(),
                $request->request->getBoolean('exigir_codigo'),
                $request->request->getBoolean('mostrar_cliente'),
                $request->request->getBoolean('incluir_analises'),
            );
        } catch (LinkExternoDoPushException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['sucesso' => true, 'link' => $link->paraJson()], Response::HTTP_CREATED);
    }

    #[Route('/{linkId}/revogar', name: 'pasta_push_links_revogar', requirements: ['linkId' => '\d+'], methods: ['POST'])]
    public function revogar(Pasta $pasta, int $linkId, Request $request, RevogarLinkExternoDoPushUseCase $revogar): JsonResponse
    {
        return $this->operarNoLink($pasta, $linkId, $request, static fn (LinkExternoDoPush $l, User $u) => $revogar->executar($l, $u));
    }

    #[Route('/{linkId}/renovar', name: 'pasta_push_links_renovar', requirements: ['linkId' => '\d+'], methods: ['POST'])]
    public function renovar(Pasta $pasta, int $linkId, Request $request, RenovarLinkExternoDoPushUseCase $renovar): JsonResponse
    {
        return $this->operarNoLink($pasta, $linkId, $request, static fn (LinkExternoDoPush $l, User $u) => $renovar->executar($l, $u));
    }

    #[Route('/{linkId}/recusar', name: 'pasta_push_links_recusar', requirements: ['linkId' => '\d+'], methods: ['POST'])]
    public function recusar(Pasta $pasta, int $linkId, Request $request, RecusarRenovacaoDoLinkUseCase $recusar): JsonResponse
    {
        return $this->operarNoLink($pasta, $linkId, $request, static fn (LinkExternoDoPush $l, User $u) => $recusar->executar($l, $u));
    }

    /**
     * Guarda + busca escopada + execução das três ações por id. A ação recebe o link já conferido.
     *
     * @param callable(LinkExternoDoPush, User): \App\Pasta\DTO\LinkExternoDoPushOutput $acao
     */
    private function operarNoLink(Pasta $pasta, int $linkId, Request $request, callable $acao): JsonResponse
    {
        $recusa = $this->recusaDoGuarda($pasta, $request);
        if ($recusa !== null) {
            return $recusa;
        }

        $tenant = $pasta->getTenant();
        $link = $tenant !== null ? $this->links->findOneDaPasta($linkId, $pasta, $tenant) : null;
        if ($link === null) {
            return $this->json(['erro' => 'Link não encontrado nesta pasta.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $saida = $acao($link, $this->usuario());
        } catch (LinkExternoDoPushException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['sucesso' => true, 'link' => $saida->paraJson()]);
    }

    /**
     * As três camadas do guarda: pasta do escritório da sessão (404, nunca 403: não revela que
     * existe), permissão de VER a pasta (403) e, na escrita, o CSRF (403).
     */
    private function recusaDoGuarda(Pasta $pasta, ?Request $escrita = null): ?JsonResponse
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->permissionChecker->canAccessResource($this->usuario(), $tenant, 'pasta', (int) $pasta->getId(), 'view')) {
            return $this->json(['erro' => 'Sem permissão para ver esta pasta.'], Response::HTTP_FORBIDDEN);
        }
        if ($escrita !== null && !$this->isCsrfTokenValid('pasta_push_link_' . $pasta->getId(), (string) $escrita->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function usuario(): User
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $usuario;
    }
}
