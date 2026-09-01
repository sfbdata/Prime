<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\DTO\ChecklistModeloOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Exception\ChecklistModeloJaExisteException;
use App\Pasta\Repository\PastaChecklistModeloRepository;
use App\Pasta\UseCase\AplicarChecklistModeloUseCase;
use App\Pasta\UseCase\ExcluirChecklistModeloUseCase;
use App\Pasta\UseCase\RenomearChecklistModeloUseCase;
use App\Pasta\UseCase\SalvarChecklistComoModeloUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Modelos de checklist de documentos: salvar a lista de uma pasta com um nome e
 * aplicá-la nas outras.
 *
 * O modelo é do ESCRITÓRIO, mas todas as rotas moram sob `/pasta/{id}` de propósito:
 * é a pasta aberta que dá o contexto de permissão (quem pode editar ESTA pasta mexe
 * nos modelos a partir dela), o mesmo guarda já usado pelos itens do checklist. Sem
 * isso, seria preciso inventar uma permissão nova só para uma lista de nomes.
 *
 * Padrão da casa em todas as ações: escritório → permissão → CSRF → posse → UseCase → JSON.
 */
#[Route('/pasta')]
final class PastaChecklistModeloController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly PastaChecklistModeloRepository $modeloRepository,
        private readonly SalvarChecklistComoModeloUseCase $salvarUseCase,
        private readonly AplicarChecklistModeloUseCase $aplicarUseCase,
        private readonly RenomearChecklistModeloUseCase $renomearUseCase,
        private readonly ExcluirChecklistModeloUseCase $excluirUseCase,
    ) {
    }

    #[Route('/{id}/checklist/modelos', name: 'pasta_checklist_modelo_listar', methods: ['GET'])]
    public function listar(Pasta $pasta): JsonResponse
    {
        if (($negado = $this->negar($pasta)) !== null) {
            return $negado;
        }

        $tenant  = $this->tenantContext->getCurrentTenant();
        $modelos = [];

        foreach ($this->modeloRepository->listarDoTenant($tenant) as $modelo) {
            $linha         = ChecklistModeloOutput::fromEntity($modelo)->toArray();
            $linha['csrf'] = $this->tokenDoModelo($modelo);
            $modelos[]     = $linha;
        }

        return $this->json(['modelos' => $modelos]);
    }

    #[Route('/{id}/checklist/modelos', name: 'pasta_checklist_modelo_salvar', methods: ['POST'])]
    public function salvar(Pasta $pasta, Request $request): JsonResponse
    {
        if (($negado = $this->negar($pasta, $request, 'checklist_modelo_pasta_' . $pasta->getId())) !== null) {
            return $negado;
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $modelo = $this->salvarUseCase->executar(
                $pasta,
                $currentUser,
                (string) $request->request->get('nome', ''),
                $this->tenantContext->getCurrentTenant(),
                $request->request->getBoolean('substituir'),
            );
        } catch (ChecklistModeloJaExisteException $e) {
            // 409 e não 422: o front usa este status para perguntar "substituir?" e repetir
            // a chamada. Misturado com a validação comum, viraria adivinhação de mensagem.
            return $this->json(['erro' => $e->getMessage(), 'jaExiste' => true, 'nome' => $e->nome], Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException) {
            return $this->json(['erro' => 'Sem permissão para esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        $linha         = ChecklistModeloOutput::fromEntity($modelo)->toArray();
        $linha['csrf'] = $this->tokenDoModelo($modelo);

        return $this->json(['modelo' => $linha], Response::HTTP_CREATED);
    }

    #[Route('/{id}/checklist/modelos/{modeloId}/aplicar', name: 'pasta_checklist_modelo_aplicar', methods: ['POST'])]
    public function aplicar(Pasta $pasta, int $modeloId, Request $request): JsonResponse
    {
        if (($negado = $this->negar($pasta, $request, 'checklist_modelo_' . $modeloId)) !== null) {
            return $negado;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $modelo = $this->modeloRepository->buscarDoTenant($modeloId, $tenant);

        if ($modelo === null) {
            return $this->json(['erro' => 'Modelo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $resultado = $this->aplicarUseCase->executar($pasta, $modelo, $tenant);

        // Cada item novo vai com o SEU token: sem isso a tela insere a linha e o primeiro
        // clique em marcar/editar/excluir volta "token inválido" — o item pareceria criado
        // pela metade. É o mesmo `csrfItem` que a rota de adicionar item já devolve.
        $criados = [];
        foreach ($resultado->criados as $criado) {
            $criado['csrfItem'] = $this->csrfTokenManager->getToken('checklist_item_' . $criado['id'])->getValue();
            $criados[]          = $criado;
        }

        return $this->json([
            'criados'        => $criados,
            'ignorados'      => $resultado->ignorados,
            'totalCriados'   => $resultado->totalCriados(),
            'totalIgnorados' => $resultado->totalIgnorados(),
            'nome'           => $modelo->getNome(),
        ]);
    }

    #[Route('/{id}/checklist/modelos/{modeloId}/renomear', name: 'pasta_checklist_modelo_renomear', methods: ['POST'])]
    public function renomear(Pasta $pasta, int $modeloId, Request $request): JsonResponse
    {
        if (($negado = $this->negar($pasta, $request, 'checklist_modelo_' . $modeloId)) !== null) {
            return $negado;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $modelo = $this->modeloRepository->buscarDoTenant($modeloId, $tenant);

        if ($modelo === null) {
            return $this->json(['erro' => 'Modelo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->renomearUseCase->executar($modelo, (string) $request->request->get('nome', ''), $tenant);
        } catch (ChecklistModeloJaExisteException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['nome' => $modelo->getNome()]);
    }

    #[Route('/{id}/checklist/modelos/{modeloId}/excluir', name: 'pasta_checklist_modelo_excluir', methods: ['POST'])]
    public function excluir(Pasta $pasta, int $modeloId, Request $request): JsonResponse
    {
        if (($negado = $this->negar($pasta, $request, 'checklist_modelo_' . $modeloId)) !== null) {
            return $negado;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $modelo = $this->modeloRepository->buscarDoTenant($modeloId, $tenant);

        if ($modelo === null) {
            return $this->json(['erro' => 'Modelo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $this->excluirUseCase->executar($modelo, $tenant);

        return $this->json(['ok' => true]);
    }

    /**
     * Escritório ativo, permissão de editar ESTA pasta e — quando a ação escreve — o CSRF.
     * Devolve a resposta de recusa, ou null quando pode seguir.
     */
    private function negar(Pasta $pasta, ?Request $request = null, ?string $tokenId = null): ?JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            return $this->json(['erro' => 'Escritório não identificado.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', (int) $pasta->getId(), 'edit')) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if ($request !== null && $tokenId !== null
            && !$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function tokenDoModelo(PastaChecklistModelo $modelo): string
    {
        return $this->csrfTokenManager
            ->getToken('checklist_modelo_' . $modelo->getId())
            ->getValue();
    }
}
