<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\UseCase\CriarPastaSecaoUseCase;
use App\Pasta\UseCase\ExcluirPastaSecaoUseCase;
use App\Pasta\UseCase\MoverDocumentoParaSecaoUseCase;
use App\Pasta\UseCase\ReordenarDocumentosUseCase;
use App\Pasta\UseCase\ReordenarSecoesUseCase;
use App\Pasta\UseCase\RenomearPastaSecaoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/pasta')]
final class PastaSecaoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $uploadsDir,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly CriarPastaSecaoUseCase $criarUseCase,
        private readonly RenomearPastaSecaoUseCase $renomearUseCase,
        private readonly ExcluirPastaSecaoUseCase $excluirUseCase,
        private readonly MoverDocumentoParaSecaoUseCase $moverUseCase,
        private readonly ReordenarDocumentosUseCase $reordenarDocumentosUseCase,
        private readonly ReordenarSecoesUseCase $reordenarSecoesUseCase,
    ) {
    }

    #[Route('/{id}/secao', name: 'pasta_secao_criar', methods: ['POST'])]
    public function criar(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_secao_criar_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $nome = trim((string) $request->request->get('nome', ''));

        try {
            $secao = $this->criarUseCase->executar($pasta, $currentUser, $nome, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id'          => $secao->getId(),
            'nome'        => $secao->getNome(),
            'ordem'       => $secao->getOrdem(),
            'csrfUpload'  => $this->csrfTokenManager->getToken('upload_documento_pasta_' . $pastaId)->getValue(),
            'csrfRenomear' => $this->csrfTokenManager->getToken('pasta_secao_renomear_' . $secao->getId())->getValue(),
            'csrfExcluir'  => $this->csrfTokenManager->getToken('pasta_secao_excluir_' . $secao->getId())->getValue(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/secao/{secaoId}/renomear', name: 'pasta_secao_renomear', methods: ['POST'])]
    public function renomear(int $secaoId, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        $secao = $this->em->find(PastaSecao::class, $secaoId);
        if ($secao === null) {
            return $this->json(['erro' => 'Seção não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $pastaId = (int) $secao->getPasta()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_secao_renomear_' . $secaoId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $novoNome = trim((string) $request->request->get('nome', ''));

        try {
            $this->renomearUseCase->executar($secao, $currentUser, $novoNome, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException $e) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['ok' => true, 'nome' => $secao->getNome()]);
    }

    #[Route('/secao/{secaoId}/excluir', name: 'pasta_secao_excluir', methods: ['POST'])]
    public function excluir(int $secaoId, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        $secao = $this->em->find(PastaSecao::class, $secaoId);
        if ($secao === null) {
            return $this->json(['erro' => 'Seção não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $pastaId = (int) $secao->getPasta()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_secao_excluir_' . $secaoId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        foreach ($secao->getDocumentos() as $doc) {
            $caminho = $this->storage->caminho($this->uploadsDir, $doc->getCaminhoArquivo());
            if ($this->storage->existe($caminho)) {
                $this->storage->excluir($caminho);
            }
        }

        try {
            $this->excluirUseCase->executar($secao, $currentUser, $tenant);
        } catch (AccessDeniedException $e) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/documento/{docId}/mover-secao', name: 'pasta_documento_mover_secao', methods: ['POST'])]
    public function moverDocumento(int $docId, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        $documento = $this->em->find(PastaDocumento::class, $docId);
        if ($documento === null) {
            return $this->json(['erro' => 'Documento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $pastaId = (int) $documento->getPasta()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_doc_mover_' . $docId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $secaoIdRaw = $request->request->get('secao_id');
        $secaoDestino = null;

        if ($secaoIdRaw !== null && $secaoIdRaw !== '') {
            $secaoDestino = $this->em->find(PastaSecao::class, (int) $secaoIdRaw);
            if ($secaoDestino === null) {
                return $this->json(['erro' => 'Seção de destino não encontrada.'], Response::HTTP_NOT_FOUND);
            }
        }

        try {
            $this->moverUseCase->executar($documento, $secaoDestino, $currentUser, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException $e) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}/documentos/reordenar', name: 'pasta_documentos_reordenar', methods: ['POST'])]
    public function reordenarDocumentos(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $data  = json_decode((string) $request->getContent(), true);
        $token = is_array($data) ? (string) ($data['_token'] ?? '') : '';
        $ids   = is_array($data['ids'] ?? null) ? $data['ids'] : [];

        if (!$this->isCsrfTokenValid('reordenar_docs_pasta_' . $pastaId, $token)) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $this->reordenarDocumentosUseCase->executar($pasta, $tenant, $ids);

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}/secoes/reordenar', name: 'pasta_secoes_reordenar', methods: ['POST'])]
    public function reordenarSecoes(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        $pastaId = (int) $pasta->getId();

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $data  = json_decode((string) $request->getContent(), true);
        $token = is_array($data) ? (string) ($data['_token'] ?? '') : '';
        $ids   = is_array($data['ids'] ?? null) ? $data['ids'] : [];

        if (!$this->isCsrfTokenValid('reordenar_secoes_pasta_' . $pastaId, $token)) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $this->reordenarSecoesUseCase->executar($pasta, $tenant, $ids);

        return $this->json(['ok' => true]);
    }

}
