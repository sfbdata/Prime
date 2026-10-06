<?php

declare(strict_types=1);

namespace App\Tarefa\Controller;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\Exception\AlertaDeMetaRecusadoException;
use App\Tarefa\Exception\TituloDeMetaInvalidoException;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use App\Tarefa\UseCase\ReabrirMetaUseCase;
use App\Tarefa\UseCase\RenomearMetaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ações da meta feitas na própria lista da aba Metas da pasta (desenho 1.2.3):
 * renomear ("Editar nome"), reabrir ("Reabrir meta") e o sino "Alertar para verificar".
 *
 * Os três são POST de formulário comum com CSRF e voltam para `pasta_show#tarefas`,
 * como o `tarefa_concluir` de sempre. A guarda é a MESMA do concluir
 * (`TarefaController::assertAccess` + `verificarAcessoTarefa`, reproduzidas aqui sem
 * mudança): módulo `tarefas` + pasta da meta pertencente ao escritório. Meta de outro
 * escritório nem chega aqui — o TenantFilter a esconde e o resolvedor responde 404.
 */
#[Route('/tarefas')]
final class MetaNaListaController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly UserTenantRepository $userTenantRepo,
    ) {
    }

    #[Route('/{id}/renomear', name: 'tarefa_renomear', methods: ['POST'])]
    public function renomear(Tarefa $tarefa, Request $request, RenomearMetaUseCase $useCase): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($tarefa, $tenant);

        if (!$this->isCsrfTokenValid('renomear_tarefa_' . $tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        try {
            if ($useCase->executar($tarefa, $tenant, (string) $request->request->get('titulo', ''))) {
                $this->addFlash('success', 'Nome da meta alterado.');
            }
        } catch (TituloDeMetaInvalidoException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->voltarParaAsMetas($tarefa);
    }

    #[Route('/{id}/reabrir', name: 'tarefa_reabrir', methods: ['POST'])]
    public function reabrir(Tarefa $tarefa, Request $request, ReabrirMetaUseCase $useCase): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($tarefa, $tenant);

        if (!$this->isCsrfTokenValid('reabrir_tarefa_' . $tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        if ($useCase->executar($tarefa, $tenant)) {
            $this->addFlash('success', 'Meta reaberta.');
        }

        return $this->voltarParaAsMetas($tarefa);
    }

    #[Route('/{id}/alertar', name: 'tarefa_alertar', methods: ['POST'])]
    public function alertar(Tarefa $tarefa, Request $request, AlertarResponsavelDaMetaUseCase $useCase): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->assertAccess($usuario);
        $this->verificarAcessoTarefa($tarefa, $tenant);

        if (!$this->isCsrfTokenValid('alertar_tarefa_' . $tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        try {
            $destinatario = $useCase->executar($tarefa, $usuario, $tenant, (int) $request->request->get('usuario', '0'));
            $this->addFlash('success', sprintf('%s foi alertado para verificar a meta.', $destinatario->getFullName()));
        } catch (AlertaDeMetaRecusadoException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->voltarParaAsMetas($tarefa);
    }

    private function voltarParaAsMetas(Tarefa $tarefa): Response
    {
        return $this->redirectToRoute('pasta_show', ['id' => $tarefa->getPasta()->getId(), '_fragment' => 'tarefas']);
    }

    /** Igual a `TarefaController::assertAccess`. */
    private function assertAccess(User $user): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Sem tenant selecionado.');
        }
        if (!$this->permissionChecker->canAccessModule($user, $tenant, 'tarefas')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Tarefas.');
        }

        return $tenant;
    }

    /** Igual a `TarefaController::verificarAcessoTarefa`. */
    private function verificarAcessoTarefa(Tarefa $tarefa, Tenant $tenant): void
    {
        $pasta       = $tarefa->getPasta();
        $criador     = $pasta->getCriadoPor();
        $responsavel = $pasta->getResponsavel();
        $criadorPertence = $criador !== null
            && $this->userTenantRepo->existeVinculoAtivo($criador, $tenant);
        $responsavelPertence = $responsavel !== null
            && $this->userTenantRepo->existeVinculoAtivo($responsavel, $tenant);

        if (!$criadorPertence && !$responsavelPertence) {
            throw $this->createAccessDeniedException('Acesso negado.');
        }
    }
}
