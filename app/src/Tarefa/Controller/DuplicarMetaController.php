<?php

declare(strict_types=1);

namespace App\Tarefa\Controller;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\Exception\MetaNaoDuplicavelException;
use App\Tarefa\Service\AcessoAMeta;
use App\Tarefa\Service\AcessoAoModuloTarefas;
use App\Tarefa\Service\ListaDeMetasDaPastaEmJson;
use App\Tarefa\UseCase\DuplicarMetaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Duplicar" no ⋮ da meta (desenho 02, D:3725/D:3736; PMC-032). A cópia é uma meta nova da mesma pasta, então as
 * guardas são as da criação de meta (`TarefaController::criarParaPasta`): módulo Tarefas, meta do escritório da
 * sessão (404 fora dele, e na pasta oculta pelo cadeado: `AcessoAMeta`), poder VER a pasta (403) e o CSRF por id.
 * A lápide e o cadeado em "Visualizar" são recusados antes, pelos listeners da pasta (a action recebe a `Tarefa`,
 * filha da pasta).
 *
 * Por XHR (o ⋮ da aba Metas) responde a aba renderizada de novo (`ListaDeMetasDaPastaEmJson`, como o concluir) com
 * o id da cópia em `copia`; o "Desfazer" do aviso manda o "Excluir" da linha nova. Sem JS, volta para a pasta com a
 * cópia realçada (`?meta=`, como a criação).
 */
#[Route('/tarefas')]
final class DuplicarMetaController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AcessoAoModuloTarefas $moduloTarefas,
        private readonly AcessoAMeta $acessoAMeta,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('/{id}/duplicar', name: 'tarefa_duplicar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicar(Tarefa $tarefa, Request $request, DuplicarMetaUseCase $useCase, ListaDeMetasDaPastaEmJson $listaEmJson): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->escritorioComModuloTarefas($usuario);
        $this->acessoAMeta->exigir($tarefa, $tenant, $usuario);
        if (($recusa = $this->recusaDaPastaOuDoToken($tarefa, $usuario, $tenant, $request)) !== null) {
            return $recusa;
        }

        try {
            $copiaId = $useCase->executar($tarefa, $usuario, $tenant);
        } catch (MetaNaoDuplicavelException $e) {
            return new JsonResponse(['sucesso' => false, 'erro' => $e->getMessage(), 'mensagem' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        if (!$request->isXmlHttpRequest()) {
            $this->addFlash('success', 'Meta duplicada.');

            return $this->redirectToRoute('pasta_show', ['id' => $tarefa->getPasta()->getId(), 'meta' => $copiaId, '_fragment' => 'tarefas']);
        }

        $dados = (array) json_decode((string) $listaEmJson->responder($tarefa, true, 'Meta duplicada')->getContent(), true);

        return new JsonResponse(['copia' => $copiaId] + $dados);
    }

    /** Igual a `TarefaController::assertAccess`. */
    private function escritorioComModuloTarefas(User $usuario): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Sem tenant selecionado.');
        }
        if (!$this->moduloTarefas->pode($usuario, $tenant)) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Tarefas.');
        }

        return $tenant;
    }

    /** VER a pasta é o mínimo para criar meta nela (C6-SEC-03), e a cópia é uma meta nova; depois, o CSRF por id. */
    private function recusaDaPastaOuDoToken(Tarefa $tarefa, User $usuario, Tenant $tenant, Request $request): ?JsonResponse
    {
        $pastaId = (int) $tarefa->getPasta()->getId();
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
            return new JsonResponse(['sucesso' => false, 'erro' => 'Sem permissão para acessar esta pasta.'], Response::HTTP_FORBIDDEN);
        }
        if (!$this->isCsrfTokenValid('duplicar_tarefa_' . $tarefa->getId(), (string) $request->request->get('_token'))) {
            return new JsonResponse(['sucesso' => false, 'erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
