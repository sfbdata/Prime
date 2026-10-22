<?php

declare(strict_types=1);

namespace App\Tarefa\Controller;

use App\Entity\Auth\User;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\DTO\CertificarCumprimentoInput;
use App\Tarefa\Exception\CertidaoRecusadaException;
use App\Tarefa\UseCase\CertificarCumprimentoUseCase;
use App\Tarefa\UseCase\ValidarRegistroDoDespachoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Certidão de cumprimento e validação do registro do despacho da meta (desenho 04B de 22/10; spec
 * `minhas-metas-esteira-2210.md`, C13 e C15). O Despachar continua na rota `tarefa_mensagem` do `TarefaController`.
 *
 * Guarda: escritório da sessão e módulo `tarefas`, como as demais rotas da meta. Despacho de outro escritório é
 * 404 (403 confirmaria que o id existe em algum lugar); quem pode certificar é regra do UseCase.
 */
#[Route('/tarefas')]
final class DespachoDaMetaController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('/mensagem/{id}/certificar', name: 'tarefa_mensagem_certificar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function certificar(TarefaMensagem $despacho, Request $request, CertificarCumprimentoUseCase $useCase, ValidatorInterface $validator): Response
    {
        [$usuario, $tenant] = $this->acesso();
        $meta = $despacho->getTarefa();
        if ($meta === null || $despacho->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Despacho não encontrado.');
        }
        if (!$this->isCsrfTokenValid('certificar_despacho_' . $despacho->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $input = new CertificarCumprimentoInput(trim((string) $request->request->get('certidao', '')));
        $erros = $validator->validate($input);
        if (count($erros) > 0) {
            $this->addFlash('danger', (string) $erros->get(0)->getMessage());

            return $this->voltarParaAMeta((int) $meta->getId());
        }

        try {
            $useCase->executar($despacho, $usuario, $tenant, $input, new \DateTimeImmutable());
            $this->addFlash('success', 'Cumprimento certificado.');
        } catch (CertidaoRecusadaException $e) {
            if ($e->ehFaltaDePermissao()) {
                throw $this->createAccessDeniedException($e->getMessage());
            }
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->voltarParaAMeta((int) $meta->getId());
    }

    #[Route('/registro/{codigo}', name: 'tarefa_registro_validar', methods: ['GET'])]
    public function validar(string $codigo, ValidarRegistroDoDespachoUseCase $useCase): Response
    {
        [$usuario, $tenant] = $this->acesso();
        $registro = $useCase->executar($codigo, $usuario, $tenant);

        return $this->render(
            'tarefa/registro.html.twig',
            ['registro' => $registro, 'codigo' => $codigo],
            new Response(null, $registro === null ? Response::HTTP_NOT_FOUND : Response::HTTP_OK),
        );
    }

    private function voltarParaAMeta(int $metaId): Response
    {
        return $this->redirectToRoute('tarefa_show', ['id' => $metaId, '_fragment' => 'tarefa-timeline-card']);
    }

    /** @return array{0: User, 1: Tenant} */
    private function acesso(): array
    {
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null) {
            throw $this->createAccessDeniedException('Sem tenant selecionado.');
        }
        if (!$this->permissionChecker->canAccessModule($usuario, $tenant, 'tarefas')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Tarefas.');
        }

        return [$usuario, $tenant];
    }
}
