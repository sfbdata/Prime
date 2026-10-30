<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\UseCase\SincronizarMarcadoresDaPastaUseCase;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\CadeadoRecusadoException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\UseCase\AlternarTrancaDaPastaUseCase;
use App\Pasta\UseCase\DestacarPastaUseCase;
use App\Pasta\UseCase\RenomearClienteDaPastaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ações da linha nas Minhas Demandas (menu de botão direito e menu MARCADOR do desenho 15, iguais aos da Lista do
 * Expediente): Editar nome rápido (F2), Trancar/Destrancar, Destacar linha e marcadores. Os mesmos UseCases do
 * Expediente, com o gate do módulo `pastas` desta tela e o token da Lista das Demandas. A guarda do escritório e
 * a permissão de editar ficam nos UseCases; a lápide e o cadeado, nos listeners da pasta.
 */
final class DemandasLinhaController extends AbstractController
{
    public function __construct(
        private readonly RenomearClienteDaPastaUseCase $renomearCliente,
        private readonly AlternarTrancaDaPastaUseCase $alternarTranca,
        private readonly DestacarPastaUseCase $destacarPasta,
        private readonly SincronizarMarcadoresDaPastaUseCase $sincronizarMarcadores,
        private readonly PastaRepository $pastaRepository,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
    ) {}

    #[Route('/demandas/pasta/{id}/nome', name: 'demandas_pasta_nome', methods: ['POST'])]
    public function renomearCliente(Pasta $pasta, Request $request): JsonResponse
    {
        [$usuario, $tenant] = $this->exigirModulo();
        if (!$this->tokenDaListaValido($request)) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $nome = $this->renomearCliente->executar($pasta, (string) $request->request->get('nome', ''), $usuario, $tenant);
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['nome' => $nome]);
    }

    #[Route('/demandas/pasta/{id}/trancar', name: 'demandas_pasta_trancar', methods: ['POST'])]
    public function alternarTranca(Pasta $pasta, Request $request): JsonResponse
    {
        [$usuario, $tenant] = $this->exigirModulo();
        if (!$this->tokenDaListaValido($request)) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $cadeado = $this->alternarTranca->executar($pasta, $usuario, $tenant);
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (CadeadoRecusadoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'trancada' => $cadeado->temCadeado,
            'por'      => $cadeado->temCadeado ? $usuario->getFullName() : null,
            'em'       => $cadeado->temCadeado ? (new \DateTimeImmutable())->format('d/m/Y') : null,
        ]);
    }

    #[Route('/demandas/pasta/{id}/destaque', name: 'demandas_pasta_destaque', methods: ['POST'])]
    public function destacar(Pasta $pasta, Request $request): JsonResponse
    {
        [$usuario, $tenant] = $this->exigirModulo();
        if (!$this->tokenDaListaValido($request)) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $cor = (string) $request->request->get('cor', '');

        try {
            $final = $this->destacarPasta->executar($pasta, $cor === '' ? null : $cor, $usuario, $tenant);
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaVerPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['cor' => $final]);
    }

    /** Menu MARCADOR da linha: define exatamente os marcadores da pasta (todos do escritório da sessão). */
    #[Route('/demandas/pasta/{id}/marcadores', name: 'demandas_pasta_marcadores', methods: ['POST'])]
    #[PastaPorId('id')]
    public function marcadores(int $id, Request $request): JsonResponse
    {
        [, $tenant] = $this->exigirModulo();
        if (!$this->isCsrfTokenValid('pasta_marcadores_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }
        // O UseCase do Expediente acha a pasta só pelo id: a posse do escritório é conferida aqui, antes.
        if ($this->pastaRepository->findOneBy(['id' => $id, 'tenant' => $tenant]) === null) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $ids = array_map('intval', array_filter($request->request->all('marcadores'), static fn ($v): bool => $v !== '' && $v !== null));

        try {
            $pasta = $this->sincronizarMarcadores->executar($id, $ids, $tenant);
        } catch (NotFoundHttpException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'pastaId'    => $pasta->getId(),
            'marcadores' => array_values(array_map(
                static fn ($m): array => ['id' => $m->getId(), 'nome' => $m->getNome(), 'cor' => $m->getCor()],
                $pasta->getMarcadores()->toArray(),
            )),
        ]);
    }

    private function tokenDaListaValido(Request $request): bool
    {
        return $this->isCsrfTokenValid(DemandasController::CSRF_LISTA, (string) $request->request->get('_token'));
    }

    /** @return array{0: User, 1: Tenant} */
    private function exigirModulo(): array
    {
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, 'pastas')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Pastas.');
        }

        return [$usuario, $tenant];
    }
}
