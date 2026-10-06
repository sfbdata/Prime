<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\UseCase\AlterarEstadoDoChecklistUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Interruptor "Ativo" do checklist de documentação (DOC-73, D8 da aba Documentos).
 *
 * Contrato: `POST /pasta/{id}/checklist/estado` com `_token` (id `checklist_pasta_<id>`, o MESMO
 * token das outras escritas do checklist), `ativo` (`1`/`0`) e, para desativar, `motivo` (um dos
 * valores de `MotivoDesativacaoChecklist`). Responde sempre JSON — quem chama é o
 * `pasta-checklist.js`, que recarrega a aba Documentos com o estado novo vindo do servidor:
 *   200 `{sucesso: true, mudou, ativo}` · 404 pasta de outro escritório · 403 CSRF ou sem permissão
 *   de editar a pasta · 422 `ativo` não booleano ou motivo fora dos quatro.
 *
 * Guardas: pasta do escritório da sessão (aqui e no UseCase — o resolver busca por PK e o
 * TenantFilter não se aplica a `find()`), CSRF por pasta, permissão de EDITAR a pasta (no UseCase,
 * a mesma das rotas `pasta_checklist_*`). Pasta excluída (lápide) recebe a recusa do
 * PastaSomenteLeituraListener, como todo POST nela. Auditoria pelo `AuditLogSubscriber`.
 */
#[Route('/pasta')]
final class PastaChecklistEstadoController extends AbstractController
{
    public function __construct(
        private readonly AlterarEstadoDoChecklistUseCase $alterarEstado,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route(
        '/{id}/checklist/estado',
        name: 'pasta_checklist_estado',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function alterar(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('checklist_pasta_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        // Ausente tem de ser recusado explicitamente: `filter_var(null, …BOOLEAN…)` devolve FALSE, e
        // um POST sem o campo viraria "desativar".
        $valorAtivo = $request->request->get('ativo');
        $ativo      = $valorAtivo === null || trim((string) $valorAtivo) === ''
            ? null
            : filter_var($valorAtivo, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($ativo === null) {
            return $this->json(['erro' => 'Informe se o checklist fica ativo ou desativado.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $mudou = $this->alterarEstado->executar(
                $pasta,
                $ativo,
                (string) $request->request->get('motivo', ''),
                $usuario,
                $tenant,
            );
        } catch (PastaDeOutroEscritorioException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'sucesso' => true,
            'mudou'   => $mudou,
            'ativo'   => $pasta->isChecklistAtivo(),
        ]);
    }
}
