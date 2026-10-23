<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Exception\AssinaturaDoDocumentoRecusadaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\UseCase\AlterarAssinaturaDoDocumentoUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Selo "Assinado" dos arquivos da aba Financeiro: "Marcar como assinado" e "Desmarcar assinatura" do ⋮.
 *
 * Mesmo recorte das ações irmãs do ⋮ (Renomear, Excluir, no `PastaController`): só arquivo da categoria
 * Contrato desta pasta (404), CSRF por documento (403). Tenant e permissão de editar a pasta ficam no
 * UseCase (404/403). Pasta na lápide recebe a recusa do `PastaSomenteLeituraListener`, que vê a `Pasta`
 * nos argumentos.
 */
#[Route('/pasta')]
final class PastaDocumentoAssinaturaController extends AbstractController
{
    public function __construct(
        private readonly AlterarAssinaturaDoDocumentoUseCase $alterarAssinatura,
        private readonly PastaDocumentoRepository $documentos,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route(
        '/{id}/financeiro/documento/{docId}/assinatura/marcar',
        name: 'pasta_financeiro_documento_marcar_assinado',
        requirements: ['id' => '\d+', 'docId' => '\d+'],
        methods: ['POST'],
    )]
    public function marcar(Pasta $pasta, int $docId, Request $request): JsonResponse
    {
        return $this->alterar($pasta, $docId, $request, true);
    }

    #[Route(
        '/{id}/financeiro/documento/{docId}/assinatura/desmarcar',
        name: 'pasta_financeiro_documento_desmarcar_assinado',
        requirements: ['id' => '\d+', 'docId' => '\d+'],
        methods: ['POST'],
    )]
    public function desmarcar(Pasta $pasta, int $docId, Request $request): JsonResponse
    {
        return $this->alterar($pasta, $docId, $request, false);
    }

    private function alterar(Pasta $pasta, int $docId, Request $request, bool $assinar): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Documento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $doc = $this->documentos->findByIdAndTenant($docId, $tenant);
        if ($doc === null || $doc->getPasta() !== $pasta || $doc->getCategoria() !== PastaDocumento::CATEGORIA_CONTRATO) {
            return $this->json(['erro' => 'Documento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('pasta_financeiro_assinatura_' . $docId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            if ($assinar) {
                $this->alterarAssinatura->marcar($pasta, $doc, $usuario, $tenant);
            } else {
                $this->alterarAssinatura->desmarcar($pasta, $doc, $usuario, $tenant);
            }
        } catch (PastaDeOutroEscritorioException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (AssinaturaDoDocumentoRecusadaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'assinado' => $doc->estaAssinado(),
            'origem'   => $doc->getAssinaturaOrigem()?->value,
        ]);
    }
}
