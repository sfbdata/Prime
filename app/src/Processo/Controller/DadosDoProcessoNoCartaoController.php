<?php

declare(strict_types=1);

namespace App\Processo\Controller;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Pasta\Entity\Pasta;
use App\Processo\DTO\DadosDoProcessoInput;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\UseCase\SalvarDadosManuaisDoProcessoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Salvar" da edição no cartão do processo (P5b, docs/specs/processo-cartao-edicao.md), na aba
 * Processo da pasta. Mesmo contrato `{sucesso, html}` do interruptor e do vincular: com XHR, o JS
 * troca o cartão pelo parcial re-renderizado; sem XHR, volta para a aba Processo.
 *
 * Ordem das recusas (nada é gravado em nenhuma): pasta de outro escritório 404 → sem ver a pasta
 * 403 → CSRF 403 → processo de outro escritório ou não vinculado a esta pasta 404 → sem EDIT no
 * processo 403 (a guarda do `processo_edit`) → campo inválido 422. A pasta excluída (lápide) é
 * recusada antes pelo PastaSomenteLeituraListener (a `Pasta` está nos argumentos).
 */
#[Route('/pasta')]
final class DadosDoProcessoNoCartaoController extends AbstractController
{
    public function __construct(
        private readonly SalvarDadosManuaisDoProcessoUseCase $salvar,
        private readonly ProcessoRepository $processos,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route(
        '/{id}/processos/{processoId}/dados',
        name: 'pasta_processo_dados_salvar',
        requirements: ['id' => '\d+', 'processoId' => '\d+'],
        methods: ['POST'],
    )]
    public function salvar(Pasta $pasta, int $processoId, Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        $isXhr = $request->isXmlHttpRequest();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->erro($pasta, $isXhr, 'Pasta não encontrada.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            return $this->erro($pasta, $isXhr, 'Sem permissão para ver esta pasta.', Response::HTTP_FORBIDDEN);
        }
        if (!$this->isCsrfTokenValid('processo_dados_' . $processoId, (string) $request->request->get('_token'))) {
            return $this->erro($pasta, $isXhr, 'Token de segurança inválido.', Response::HTTP_FORBIDDEN);
        }

        $processo = $this->processos->findOneByIdDoTenant($processoId, $tenant);
        if ($processo === null || !in_array($processo, $pasta->getProcessos(), true)) {
            return $this->erro($pasta, $isXhr, 'Processo não encontrado nesta pasta.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PROCESSO, $processoId, AccessRequest::ACTION_EDIT)) {
            return $this->erro($pasta, $isXhr, 'Sem permissão para editar este processo.', Response::HTTP_FORBIDDEN);
        }

        try {
            $pastas = $this->salvar->executar($processo, $pasta, DadosDoProcessoInput::fromRequest($request), $usuario, $tenant);
        } catch (DadosDoProcessoInvalidosException $e) {
            return $this->erro($pasta, $isXhr, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $aviso = $pastas > 1
            ? 'Dados do processo salvos. Valem para todas as pastas com este processo.'
            : 'Dados do processo salvos.';

        if ($isXhr) {
            return $this->json([
                'sucesso' => true,
                'aviso' => $aviso,
                'html' => $this->renderView('pasta/_processos_vinculados.html.twig', [
                    'pasta' => $pasta,
                    'podeEditarPasta' => $this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT),
                ]),
            ]);
        }

        $this->addFlash('success', $aviso);

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'processo']);
    }

    private function erro(Pasta $pasta, bool $isXhr, string $mensagem, int $status): Response
    {
        if ($isXhr) {
            return $this->json(['erro' => $mensagem], $status);
        }
        if ($status === Response::HTTP_NOT_FOUND) {
            throw $this->createNotFoundException($mensagem);
        }
        if ($status === Response::HTTP_FORBIDDEN) {
            throw $this->createAccessDeniedException($mensagem);
        }
        $this->addFlash('danger', $mensagem);

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'processo']);
    }
}
