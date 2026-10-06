<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\DuplicarPastaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Sync\Service\SincronizacaoPastaDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Duplicar pasta" do menu ⋮ do cabeçalho (desenho 1.2.3): abre uma pasta nova com cliente,
 * ação, responsável e checklist da origem. O que vai e o que não vai mora no
 * `DuplicarPastaUseCase`.
 *
 * Guarda em quatro passos, nesta ordem:
 *   1. a pasta é do escritório da sessão — o resolver de entidade busca por PK e o TenantFilter
 *      não se aplica a find(), então a conferência é explícita (404, sem revelar que existe);
 *   2. o usuário pode VER a pasta de origem — duplicar copia dados dela;
 *   3. o usuário pode CRIAR pasta — a mesma permissão do `pasta_new` (módulo `pastas`);
 *   4. CSRF.
 *
 * Pasta excluída (lápide) é recusada antes de chegar aqui pelo `PastaSomenteLeituraListener`
 * (POST que recebe uma Pasta riscada); o UseCase recusa de novo, para valer por qualquer porta.
 *
 * O disparo do Drive é o mesmo do `pasta_new`: a pasta nova é criada no Drive pelo worker se o
 * escritório tiver Drive conectado. Fica aqui, e não no UseCase, pelo mesmo motivo de lá (ver
 * `SincronizacaoPastaDispatcher`).
 */
#[Route('/pasta')]
final class PastaDuplicarController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly DuplicarPastaUseCase $duplicarPasta,
        private readonly SincronizacaoPastaDispatcher $syncDispatcher,
    ) {
    }

    #[Route('/{id}/duplicar', name: 'pasta_duplicar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicar(Pasta $pasta, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', (int) $pasta->getId(), 'view')) {
            throw $this->createAccessDeniedException('Sem permissão para ver esta pasta.');
        }

        if (!$this->permissionChecker->canAccessModule($currentUser, $tenant, 'pastas')) {
            throw $this->createAccessDeniedException('Sem permissão para criar pasta.');
        }

        if (!$this->isCsrfTokenValid('pasta_duplicar_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        try {
            $nova = $this->duplicarPasta->executar($pasta, $currentUser, $tenant);
        } catch (\DomainException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
        }

        $this->syncDispatcher->despachar($nova, $currentUser, $tenant);

        $this->addFlash('success', sprintf(
            'Pasta %s criada como cópia da pasta %s. Documentos, metas e financeiro não foram copiados.',
            $nova->getNup(),
            $pasta->getNup(),
        ));

        return $this->redirectToRoute('pasta_show', ['id' => $nova->getId()]);
    }
}
