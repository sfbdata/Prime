<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AbaOcultaResultadoOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\AbaOcultaRecusadaException;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Pasta\UseCase\DefinirQuemNaoVeAbaUseCase;
use App\Pasta\UseCase\ListarEquipeParaOcultarAbaUseCase;
use App\Pasta\UseCase\MostrarAbaParaTodosUseCase;
use App\Pasta\UseCase\OcultarAbaParaTodosUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ocultar aba da pasta (spec pasta-ocultar-aba §3): a lista do modal e as três escritas do botão
 * direito da aba. Só JSON; a tela é o `pasta-abas-ocultas.js`.
 *
 * Guarda, nesta ordem: a pasta é do escritório da sessão (404, nunca 403: não revela que existe), o
 * usuário pode VER a pasta (403), quem pede configura, isto é, é Master ou primário (403 com o texto
 * do desenho), e a aba existe (404). Escrita com CSRF `pasta_aba_oculta_{pastaId}` no campo `_token`.
 */
#[Route('/pasta/{id}/abas/{aba}', requirements: ['id' => '\d+'])]
final class PastaAbaOcultaController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly VisibilidadeDasAbasDaPasta $visibilidade,
    ) {
    }

    #[Route('/equipe', name: 'pasta_aba_equipe', methods: ['GET'])]
    public function equipe(Pasta $pasta, string $aba, ListarEquipeParaOcultarAbaUseCase $listar): JsonResponse
    {
        $recusa = $this->recusaDoGuarda($pasta, $aba);
        if ($recusa !== null) {
            return $recusa;
        }

        return $this->json($listar->executar($pasta, AbaDaPasta::from($aba), $this->tenant())->paraJson());
    }

    #[Route('/ocultar-todos', name: 'pasta_aba_ocultar_todos', methods: ['POST'])]
    public function ocultarTodos(Pasta $pasta, string $aba, Request $request, OcultarAbaParaTodosUseCase $ocultar): JsonResponse
    {
        return $this->escrever($pasta, $aba, $request, fn (AbaDaPasta $a): AbaOcultaResultadoOutput => $ocultar->executar($pasta, $a, $this->usuario(), $this->tenant()));
    }

    #[Route('/ocultar-para', name: 'pasta_aba_ocultar_para', methods: ['POST'])]
    public function ocultarPara(Pasta $pasta, string $aba, Request $request, DefinirQuemNaoVeAbaUseCase $definir): JsonResponse
    {
        $ids = array_values(array_filter(
            array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $request->request->all('usuarios')),
            static fn (int $id): bool => $id > 0,
        ));

        return $this->escrever($pasta, $aba, $request, fn (AbaDaPasta $a): AbaOcultaResultadoOutput => $definir->executar($pasta, $a, $this->usuario(), $this->tenant(), $ids));
    }

    #[Route('/mostrar', name: 'pasta_aba_mostrar', methods: ['POST'])]
    public function mostrar(Pasta $pasta, string $aba, Request $request, MostrarAbaParaTodosUseCase $mostrar): JsonResponse
    {
        return $this->escrever($pasta, $aba, $request, fn (AbaDaPasta $a): AbaOcultaResultadoOutput => $mostrar->executar($pasta, $a, $this->usuario(), $this->tenant()));
    }

    /** @param callable(AbaDaPasta): AbaOcultaResultadoOutput $acao */
    private function escrever(Pasta $pasta, string $aba, Request $request, callable $acao): JsonResponse
    {
        $recusa = $this->recusaDoGuarda($pasta, $aba, $request);
        if ($recusa !== null) {
            return $recusa;
        }

        try {
            $resultado = $acao(AbaDaPasta::from($aba));
        } catch (AbaOcultaRecusadaException $e) {
            return $this->json(['erro' => $e->getMessage()], $e->ehPastaDeOutroEscritorio() ? Response::HTTP_NOT_FOUND : Response::HTTP_FORBIDDEN);
        }

        return $this->json($resultado->paraJson());
    }

    private function recusaDoGuarda(Pasta $pasta, string $aba, ?Request $escrita = null): ?JsonResponse
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->permissionChecker->canAccessResource($this->usuario(), $tenant, 'pasta', (int) $pasta->getId(), 'view')) {
            return $this->json(['erro' => 'Sem permissão para ver esta pasta.'], Response::HTTP_FORBIDDEN);
        }
        if (!$this->visibilidade->podeConfigurar($this->usuario(), $tenant)) {
            return $this->json(['erro' => AbaOcultaRecusadaException::SO_PRIMARIO], Response::HTTP_FORBIDDEN);
        }
        if (AbaDaPasta::tryFrom($aba) === null) {
            return $this->json(['erro' => 'Aba desconhecida.'], Response::HTTP_NOT_FOUND);
        }
        if ($escrita !== null && !$this->isCsrfTokenValid('pasta_aba_oculta_' . $pasta->getId(), (string) $escrita->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException();
        }

        return $tenant;
    }

    private function usuario(): User
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $usuario;
    }
}
