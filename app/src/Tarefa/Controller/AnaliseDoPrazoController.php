<?php

declare(strict_types=1);

namespace App\Tarefa\Controller;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\DTO\AnalisarPrazoDaMetaInput;
use App\Tarefa\DTO\AnaliseDoPrazoOutput;
use App\Tarefa\Service\AcessoAoModuloTarefas;
use App\Tarefa\UseCase\AnalisarPrazoDaMetaNovaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Análise do prazo" do modal "Nova meta" da pasta (desenho 02). Rota leve, só leitura: o modal a chama a cada
 * alteração do título, da descrição ou das datas de origem. As guardas são as da criação da meta
 * (`TarefaController::criarParaPasta`): módulo Tarefas, pasta do escritório da sessão (404), poder VER a pasta (403)
 * e o mesmo token CSRF do formulário.
 */
#[Route('/tarefas')]
final class AnaliseDoPrazoController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AcessoAoModuloTarefas $moduloTarefas,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('/pasta/{pastaId}/analise-do-prazo', name: 'tarefa_analise_do_prazo', requirements: ['pastaId' => '\d+'], methods: ['POST'])]
    #[PastaPorId('pastaId')]
    public function analisar(int $pastaId, Request $request, PastaRepository $pastas, AnalisarPrazoDaMetaNovaUseCase $useCase): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->escritorioComModuloTarefas($usuario);

        $pasta = $pastas->find($pastaId);
        if (null === $pasta || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            return $this->json(['erro' => 'Pasta não encontrada.'], 404);
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            return $this->json(['erro' => 'Sem permissão para acessar esta pasta.'], 403);
        }
        if (!$this->isCsrfTokenValid('tarefa_criar_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], 403);
        }

        $analise = $useCase->executar(
            new AnalisarPrazoDaMetaInput(
                trim((string) $request->request->get('titulo')),
                trim((string) $request->request->get('descricao')),
                AnalisarPrazoDaMetaInput::dataDoCampo($request->request->get('data_publicacao')),
                AnalisarPrazoDaMetaInput::dataDoCampo($request->request->get('data_ciencia')),
                AnalisarPrazoDaMetaInput::dataDoCampo($request->request->get('prazo_juridico')),
                '1' === (string) $request->request->get('analise_confirmada'),
            ),
            new \DateTimeImmutable('today'),
        );

        return $this->json(['analise' => null === $analise ? null : AnaliseDoPrazoOutput::fromAnalise($analise)->dados]);
    }

    private function escritorioComModuloTarefas(User $usuario): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (null === $tenant) {
            throw $this->createAccessDeniedException('Sem tenant selecionado.');
        }
        if (!$this->moduloTarefas->pode($usuario, $tenant)) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Tarefas.');
        }

        return $tenant;
    }
}
