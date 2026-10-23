<?php

declare(strict_types=1);

namespace App\Tarefa\Controller;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\Exception\LembreteRecusadoException;
use App\Tarefa\Service\AcessoAMeta;
use App\Tarefa\UseCase\LembrarMetaUseCase;
use App\Tarefa\UseCase\MontarExtratoDaMetaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Extrato e Lembrar da página da meta (desenho 04B de 22/10, L50, L57, L133 e L150; spec
 * `minhas-metas-esteira-2210.md`, Fase 2b, L9 e L10).
 *
 * Guarda: a MESMA da página da meta. Escritório da sessão e módulo `tarefas` (403), e a posse da meta pelo
 * {@see AcessoAMeta} (outro escritório: 404). O Lembrar ainda exige o token CSRF.
 */
#[Route('/tarefas')]
final class TramitacaoDaMetaController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AcessoAMeta $acessoAMeta,
    ) {
    }

    /** A folha abre em janela nova e já pede a impressão (L150: `w.print()`); "Salvar como PDF" é do navegador. */
    #[Route('/{id}/extrato', name: 'tarefa_extrato', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function extrato(Tarefa $tarefa, MontarExtratoDaMetaUseCase $useCase): Response
    {
        [$usuario, $tenant] = $this->acesso($tarefa);

        $resposta = $this->render('tarefa/extrato.html.twig', [
            'extrato' => $useCase->executar($tarefa, $usuario, $tenant, new \DateTimeImmutable()),
        ]);
        $resposta->headers->set('X-Robots-Tag', 'noindex');

        return $resposta;
    }

    #[Route('/{id}/lembrar', name: 'tarefa_lembrar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function lembrar(Tarefa $tarefa, Request $request, LembrarMetaUseCase $useCase): Response
    {
        [$usuario, $tenant] = $this->acesso($tarefa);
        if (!$this->isCsrfTokenValid('lembrar_meta_' . $tarefa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        try {
            $useCase->executar($tarefa, $usuario, $tenant, new \DateTimeImmutable());
        } catch (LembreteRecusadoException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId()]);
        }

        // "Lembrete enviado no Chat I.A." (L133) é o toast da página, pedido pela URL (o base consome os flashes).
        return $this->redirectToRoute('tarefa_show', ['id' => $tarefa->getId(), 'lembrete' => 'enviado']);
    }

    /** @return array{0: User, 1: Tenant} */
    private function acesso(Tarefa $meta): array
    {
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null) {
            throw $this->createAccessDeniedException('Sem tenant selecionado.');
        }
        if (!$this->permissionChecker->canAccessModule($usuario, $tenant, 'tarefas')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Tarefas.');
        }
        $this->acessoAMeta->exigir($meta, $tenant, $usuario);

        return [$usuario, $tenant];
    }
}
