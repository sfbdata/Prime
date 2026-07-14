<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\Enum\CategoriaDocumentoCobranca;
use App\Cobranca\Exception\ArquivoMuitoGrandeException;
use App\Cobranca\Exception\SecaoNaoEncontradaException;
use App\Cobranca\Exception\TipoArquivoNaoPermitidoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Cobranca\UseCase\CriarSecaoUseCase;
use App\Cobranca\UseCase\EnviarDocumentoUseCase;
use App\Cobranca\UseCase\ExcluirDocumentoUseCase;
use App\Cobranca\UseCase\ExcluirSecaoUseCase;
use App\Cobranca\UseCase\MoverDocumentoUseCase;
use App\Cobranca\UseCase\RenomearSecaoUseCase;
use App\Cobranca\UseCase\ReordenarDocumentosCasoUseCase;
use App\Cobranca\UseCase\ReordenarSecoesCasoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * File-manager de documentos de um Caso de Cobrança (Etapa 8C, SPEC §15, invariável 25 — documento
 * vive no Caso, nunca na Pasta). Serve o MESMO contrato `data-*`/JSON do gerenciador das Pastas
 * (`public/js/pasta-arquivos.js`, reusado sem edição): as respostas por ação seguem exatamente o
 * formato que o JS espera (upload → `{success:true,...}`; seção/mover → `{ok:true}`; erro → `{erro}`).
 *
 * Controller FINO: gate de módulo + capacidade `resources.cobranca.gerenciar` nas mutações (falha →
 * JSON 403, pois é AJAX), resolução tenant-safe por id (`findOneByIdDoTenant` → 404 anti-IDOR), CSRF
 * nomeado por ação (stateful, espelhando as Pastas), e delega aos UseCases da Etapa 6. Nenhuma regra
 * de negócio aqui — os UseCases flusham e guardam tenant.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class DocumentoCobrancaController extends AbstractController
{
    use AutorizacaoCobranca;

    private const CAPACIDADE = 'resources.cobranca.gerenciar';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly CobrancaSecaoRepository $secaoRepository,
        private readonly CobrancaDocumentoRepository $documentoRepository,
        private readonly ArquivoStorageInterface $storage,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly EnviarDocumentoUseCase $enviarDocumento,
        private readonly MoverDocumentoUseCase $moverDocumento,
        private readonly ExcluirDocumentoUseCase $excluirDocumento,
        private readonly CriarSecaoUseCase $criarSecao,
        private readonly RenomearSecaoUseCase $renomearSecao,
        private readonly ExcluirSecaoUseCase $excluirSecao,
        private readonly ReordenarDocumentosCasoUseCase $reordenarDocumentos,
        private readonly ReordenarSecoesCasoUseCase $reordenarSecoes,
        private readonly string $cobrancasUploadsDir,
    ) {
    }

    // ------------------------------------------------------------- upload -----

    #[Route('/casos/{id}/documentos', name: 'cobranca_documento_upload', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function upload(int $id, Request $request): JsonResponse
    {
        $tenant = $this->tenantComCapacidade(self::CAPACIDADE);
        if ($tenant === null) {
            return $this->json(['success' => false, 'error' => 'Sem permissão para gerenciar documentos.'], Response::HTTP_FORBIDDEN);
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            return $this->json(['success' => false, 'error' => 'Caso não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('cobranca_documento_upload_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['success' => false, 'error' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $arquivo = $request->files->get('arquivo');
        if (!$arquivo instanceof UploadedFile) {
            return $this->json(['success' => false, 'error' => 'Nenhum arquivo enviado.'], Response::HTTP_BAD_REQUEST);
        }

        $secao = null;
        $secaoIdRaw = $request->request->get('secao_id');
        if ($secaoIdRaw !== null && $secaoIdRaw !== '') {
            $secao = $this->secaoRepository->findOneByIdDoTenant((int) $secaoIdRaw, $tenant);
            if ($secao === null) {
                return $this->json(['success' => false, 'error' => 'Seção não encontrada.'], Response::HTTP_NOT_FOUND);
            }
        }

        $categoria = CategoriaDocumentoCobranca::tryFrom(strtolower((string) $request->request->get('categoria', '')))
            ?? CategoriaDocumentoCobranca::Outro;
        $descricao = trim((string) $request->request->get('descricao', ''));
        $reduzir = in_array((string) $request->request->get('reduzir_tamanho', '0'), ['1', 'true', 'on'], true);

        try {
            $documento = $this->enviarDocumento->executar($caso, $secao, $arquivo, $categoria, $descricao !== '' ? $descricao : null, $tenant, $reduzir);
        } catch (TipoArquivoNaoPermitidoException | ArquivoMuitoGrandeException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SecaoNaoEncontradaException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (AccessDeniedException $e) {
            return $this->json(['success' => false, 'error' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'documento' => [
                'id' => $documento->getId(),
                'titulo' => $documento->getTitulo(),
                'categoria' => $documento->getCategoria()->value,
                'uploadedAt' => $documento->getCarregadoEm()->format('d/m/Y H:i'),
                'downloadUrl' => $this->generateUrl('cobranca_documento_download', ['docId' => $documento->getId()]),
                'mimeType' => $documento->getMimeType(),
            ],
        ], Response::HTTP_CREATED);
    }

    // ------------------------------------------------------------- seções -----

    #[Route('/casos/{id}/secoes', name: 'cobranca_secao_criar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function criarSecao(int $id, Request $request): JsonResponse
    {
        $tenant = $this->tenantComCapacidade(self::CAPACIDADE);
        if ($tenant === null) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            return $this->json(['erro' => 'Caso não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('cobranca_secao_criar_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $nome = trim((string) $request->request->get('nome', ''));

        try {
            $secao = $this->criarSecao->executar($caso, $nome, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id' => $secao->getId(),
            'nome' => $secao->getNome(),
            'ordem' => $secao->getOrdem(),
            'csrfUpload' => $this->csrfTokenManager->getToken('cobranca_documento_upload_' . $id)->getValue(),
            'csrfRenomear' => $this->csrfTokenManager->getToken('cobranca_secao_renomear_' . $secao->getId())->getValue(),
            'csrfExcluir' => $this->csrfTokenManager->getToken('cobranca_secao_excluir_' . $secao->getId())->getValue(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/secoes/{secaoId}/renomear', name: 'cobranca_secao_renomear', methods: ['POST'])]
    public function renomearSecao(int $secaoId, Request $request): JsonResponse
    {
        $tenant = $this->tenantComCapacidade(self::CAPACIDADE);
        if ($tenant === null) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $secao = $this->secaoRepository->findOneByIdDoTenant($secaoId, $tenant);
        if ($secao === null) {
            return $this->json(['erro' => 'Seção não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('cobranca_secao_renomear_' . $secaoId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $novoNome = trim((string) $request->request->get('nome', ''));

        try {
            $this->renomearSecao->executar($secao, $novoNome, $tenant);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException $e) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['ok' => true, 'nome' => $secao->getNome()]);
    }

    #[Route('/secoes/{secaoId}/excluir', name: 'cobranca_secao_excluir', methods: ['POST'])]
    public function excluirSecao(int $secaoId, Request $request): JsonResponse
    {
        $tenant = $this->tenantComCapacidade(self::CAPACIDADE);
        if ($tenant === null) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $secao = $this->secaoRepository->findOneByIdDoTenant($secaoId, $tenant);
        if ($secao === null) {
            return $this->json(['erro' => 'Seção não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('cobranca_secao_excluir_' . $secaoId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->excluirSecao->executar($secao, $tenant);
        } catch (AccessDeniedException $e) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['ok' => true]);
    }

    // ---------------------------------------------------------- documentos ----

    #[Route('/documentos/{docId}/mover', name: 'cobranca_documento_mover', methods: ['POST'], requirements: ['docId' => '\d+'])]
    public function moverDocumento(int $docId, Request $request): JsonResponse
    {
        $tenant = $this->tenantComCapacidade(self::CAPACIDADE);
        if ($tenant === null) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $documento = $this->documentoRepository->findOneByIdDoTenant($docId, $tenant);
        if ($documento === null) {
            return $this->json(['erro' => 'Documento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('cobranca_doc_mover_' . $docId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $secaoDestino = null;
        $secaoIdRaw = $request->request->get('secao_id');
        if ($secaoIdRaw !== null && $secaoIdRaw !== '') {
            $secaoDestino = $this->secaoRepository->findOneByIdDoTenant((int) $secaoIdRaw, $tenant);
            if ($secaoDestino === null) {
                return $this->json(['erro' => 'Seção de destino não encontrada.'], Response::HTTP_NOT_FOUND);
            }
        }

        try {
            $this->moverDocumento->executar($documento, $secaoDestino, $tenant);
        } catch (SecaoNaoEncontradaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AccessDeniedException $e) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/documentos/{docId}/excluir', name: 'cobranca_documento_excluir', methods: ['POST'], requirements: ['docId' => '\d+'])]
    public function excluirDocumento(int $docId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade(self::CAPACIDADE);
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $documento = $this->documentoRepository->findOneByIdDoTenant($docId, $tenant);
        if ($documento === null) {
            throw $this->createNotFoundException('Documento não encontrado.');
        }
        $objetoId = $this->objetoIdDoCaso($documento->getCaso());

        if (!$this->isCsrfTokenValid('delete_documento_cobranca_' . $docId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId]);
        }

        try {
            $this->excluirDocumento->executar($documento, $tenant);
            $this->addFlash('success', 'Documento removido.');
        } catch (AccessDeniedException $e) {
            $this->addFlash('danger', 'Sem permissão.');
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId]);
    }

    #[Route('/documentos/{docId}/download', name: 'cobranca_documento_download', methods: ['GET'], requirements: ['docId' => '\d+'])]
    public function download(int $docId): BinaryFileResponse
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo de Cobranças.');
        }

        $documento = $this->documentoRepository->findOneByIdDoTenant($docId, $tenant);
        if ($documento === null) {
            throw $this->createNotFoundException('Documento não encontrado.');
        }

        $caminho = $this->storage->caminho($this->cobrancasUploadsDir . '/' . $tenant->getId(), $documento->getCaminhoArquivo());
        if (!$this->storage->existe($caminho)) {
            throw $this->createNotFoundException('Arquivo não encontrado no armazenamento.');
        }

        return $this->storage->servir($caminho, $documento->getNomeOriginal(), inline: false);
    }

    // -------------------------------------------------------- reordenação -----

    #[Route('/casos/{id}/documentos/reordenar', name: 'cobranca_documentos_reordenar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reordenarDocumentos(int $id, Request $request): JsonResponse
    {
        $tenant = $this->tenantComCapacidade(self::CAPACIDADE);
        if ($tenant === null) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            return $this->json(['erro' => 'Caso não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        [$token, $ids] = $this->corpoJson($request);
        if (!$this->isCsrfTokenValid('reordenar_docs_cobranca_' . $id, $token)) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $this->reordenarDocumentos->executar($caso, $tenant, $ids);

        return $this->json(['ok' => true]);
    }

    #[Route('/casos/{id}/secoes/reordenar', name: 'cobranca_secoes_reordenar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reordenarSecoes(int $id, Request $request): JsonResponse
    {
        $tenant = $this->tenantComCapacidade(self::CAPACIDADE);
        if ($tenant === null) {
            return $this->json(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            return $this->json(['erro' => 'Caso não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        [$token, $ids] = $this->corpoJson($request);
        if (!$this->isCsrfTokenValid('reordenar_secoes_cobranca_' . $id, $token)) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $this->reordenarSecoes->executar($caso, $tenant, $ids);

        return $this->json(['ok' => true]);
    }

    /**
     * Extrai `_token` (string) e `ids` (list<int>) do corpo JSON dos endpoints de reordenação.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function corpoJson(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);
        $token = is_array($data) ? (string) ($data['_token'] ?? '') : '';
        $ids = (is_array($data) && is_array($data['ids'] ?? null)) ? array_map('intval', $data['ids']) : [];

        return [$token, $ids];
    }
}
