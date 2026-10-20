<?php

declare(strict_types=1);

namespace App\Inteligencia\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\FiltroDoAgenteInput;
use App\Inteligencia\Exception\FiltroDoAgenteInvalidoException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\UseCase\BuscarPastasDoAgenteUseCase;
use App\Inteligencia\UseCase\ContarPastasDoAgenteUseCase;
use App\Inteligencia\UseCase\ListarDocumentosDaPastaDoAgenteUseCase;
use App\Inteligencia\UseCase\ListarOpcoesDoAgenteUseCase;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ferramentas de LEITURA do Agente de Pastas (Fase 1 de `ESPEC_AGENTE_PASTAS.md`; contrato em
 * `docs/specs/designer-central-ia.md` §3). Só GET, sem efeito colateral — por isso sem CSRF.
 *
 * Porta de entrada: o módulo Expediente, o mesmo da Lista, porque a busca devolve o que a Lista mostra.
 * Os documentos exigem ainda o acesso por item à pasta (no UseCase).
 */
#[Route('/api/agente')]
final class AgenteDePastasController extends AbstractController
{
    public function __construct(
        private readonly BuscarPastasDoAgenteUseCase $buscarPastas,
        private readonly ContarPastasDoAgenteUseCase $contarPastas,
        private readonly ListarDocumentosDaPastaDoAgenteUseCase $listarDocumentos,
        private readonly ListarOpcoesDoAgenteUseCase $listarOpcoes,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/pastas', name: 'inteligencia_agente_pastas_buscar', methods: ['GET'])]
    public function buscar(Request $request): JsonResponse
    {
        $tenant = $this->tenantComModulo();

        try {
            $filtro = FiltroDoAgenteInput::daQuery($request->query->all());
            if ($filtro->contar) {
                return $this->json(['total' => $this->contarPastas->executar($filtro, $tenant)]);
            }

            return $this->json($this->buscarPastas->executar($filtro, $tenant));
        } catch (FiltroDoAgenteInvalidoException $e) {
            return $this->json(['erro' => $e->getMessage(), 'campo' => $e->campo], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    // `#[PastaPorId]`: a barreira do ocultar aba acha a pasta pelo id e recusa com a aba Documentos oculta.
    #[Route('/pastas/{id}/documentos', name: 'inteligencia_agente_pastas_documentos', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[PastaPorId('id')]
    public function documentos(int $id): JsonResponse
    {
        $tenant = $this->tenantComModulo();
        /** @var User $usuario */
        $usuario = $this->getUser();

        try {
            $saida = $this->listarDocumentos->executar($id, $usuario, $tenant);
        } catch (PastaNaoEncontradaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaVerPastaException) {
            return $this->json(['erro' => 'Sem acesso a esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'pasta'      => ['id' => $saida->pastaId, 'nup' => $saida->nup],
            'documentos' => $saida->documentos,
        ]);
    }

    #[Route('/opcoes', name: 'inteligencia_agente_opcoes', methods: ['GET'])]
    public function opcoes(): JsonResponse
    {
        return $this->json($this->listarOpcoes->executar($this->tenantComModulo()));
    }

    private function tenantComModulo(): Tenant
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, 'expediente')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Expediente.');
        }

        return $tenant;
    }
}
