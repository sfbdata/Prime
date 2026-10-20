<?php

declare(strict_types=1);

namespace App\Cliente\Controller;

use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\DocumentoDaPastaNaoEncontradoException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\UseCase\MontarInteligenciaCadastralUseCase;
use App\Cliente\UseCase\VincularDocumentoDaPastaAoClienteUseCase;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bloco "Inteligência cadastral" da aba Documentos do cadastro (F-A; spec docs/specs/cliente-inteligencia-cadastral.md, §3).
 *
 * GET devolve o retrato do bloco em JSON (carregado ao abrir a aba). POST vincula ao cadastro um documento da pasta,
 * sem cópia do arquivo; CSRF no header `X-CSRF-Token`, com o token da janela de Cadastro. Cliente de outro escritório
 * ou que o usuário não vê: 404 nas duas.
 */
final class ClienteInteligenciaCadastralController extends AbstractController
{
    public function __construct(
        private readonly ClienteRepository $clienteRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly MontarInteligenciaCadastralUseCase $montar,
        private readonly VincularDocumentoDaPastaAoClienteUseCase $vincular,
    ) {
    }

    #[Route('/cliente/{id}/inteligencia-cadastral', name: 'cliente_inteligencia_cadastral', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function retrato(int $id): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        [$cliente, $tenant] = $this->carregarVisivel($id, $usuario);
        try {
            $saida = $this->montar->executar($cliente, $usuario, $tenant);
        } catch (ClienteNaoEncontradoException) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        return $this->json($saida->paraResposta());
    }

    #[Route('/cliente/{id}/inteligencia-cadastral/vincular/{documentoId}', name: 'cliente_inteligencia_cadastral_vincular', requirements: ['id' => '\d+', 'documentoId' => '\d+'], methods: ['POST'])]
    public function vincular(int $id, int $documentoId, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        [$cliente, $tenant] = $this->carregarVisivel($id, $usuario);
        if (!$this->isCsrfTokenValid(ClienteCadastroController::idDoToken($id), (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página e tente de novo.'], Response::HTTP_FORBIDDEN);
        }
        try {
            $criou = $this->vincular->executar($cliente, $documentoId, $usuario, $tenant);
        } catch (SemPermissaoParaEditarClienteException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (DocumentoDaPastaNaoEncontradoException|ClienteNaoEncontradoException $e) {
            return $this->json(['erro' => 'Documento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['ok' => true, 'vinculado' => $criou, 'mensagem' => 'Documento vinculado ao cadastro.']);
    }

    /** @return array{0: Cliente, 1: Tenant} */
    private function carregarVisivel(int $id, User $usuario): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $cliente = $tenant !== null ? $this->clienteRepository->find($id) : null;
        if ($tenant === null || $cliente === null || $cliente->getTenant()?->getId() !== $tenant->getId()
            || !$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_VIEW)) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        return [$cliente, $tenant];
    }
}
