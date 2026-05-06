<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Cliente\Entity\Cliente;
use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaDocumento;
use App\Pasta\DTO\UploadImagemEditorInput;
use App\Pasta\UseCase\EditarPecaTextoUseCase;
use App\Pasta\UseCase\ExportarPecaTextoUseCase;
use App\Pasta\UseCase\SalvarPecaTextoUseCase;
use App\Pasta\UseCase\UploadImagemEditorUseCase;
use App\Pasta\UseCase\UploadPecaUseCase;
use App\Service\PermissionChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pasta')]
final class PeticionarController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly UploadPecaUseCase $uploadPecaUseCase,
        private readonly SalvarPecaTextoUseCase $salvarPecaTextoUseCase,
        private readonly EditarPecaTextoUseCase $editarPecaTextoUseCase,
        private readonly ExportarPecaTextoUseCase $exportarPecaTextoUseCase,
        private readonly UploadImagemEditorUseCase $uploadImagemEditorUseCase,
        private readonly string $uploadsDir,
    ) {}

    #[Route('/{id}/peticionar', name: 'pasta_peticionar', methods: ['GET'])]
    public function show(Pasta $pasta): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if (!$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pasta->getId(), 'view')) {
            throw $this->createAccessDeniedException();
        }

        $documentos = $pasta->getDocumentos()->toArray();
        usort(
            $documentos,
            static fn (PastaDocumento $a, PastaDocumento $b) => $a->getUploadedAt() <=> $b->getUploadedAt(),
        );

        $primeiroCliente = $pasta->getClientes()->first();
        $nomeCliente     = ($primeiroCliente instanceof Cliente) ? $primeiroCliente->getNomeExibicao() : null;

        return $this->render('pasta/peticionar.html.twig', [
            'pasta'           => $pasta,
            'documentos'      => $documentos,
            'nomeCliente'     => $nomeCliente,
            'categoriaLabels' => [
                PastaDocumento::CATEGORIA_PECA                    => 'Peça',
                PastaDocumento::CATEGORIA_PROCURACAO              => 'Procuração',
                PastaDocumento::CATEGORIA_IDENTIFICACAO           => 'Identificação',
                PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA  => 'Comp. Residência',
                PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA      => 'Gratuidade',
                PastaDocumento::CATEGORIA_DEMAIS                  => 'Demais',
                PastaDocumento::CATEGORIA_CONTRATO                => 'Contrato',
            ],
        ]);
    }

    #[Route('/{id}/peticionar/upload', name: 'pasta_peticionar_upload', methods: ['POST'])]
    public function upload(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if (!$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pasta->getId(), 'edit')) {
            return new JsonResponse(['success' => false, 'error' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('peticionar_upload_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'error' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $arquivo = $request->files->get('arquivo');
        if ($arquivo === null) {
            return new JsonResponse(['success' => false, 'error' => 'Nenhum arquivo enviado.'], Response::HTTP_BAD_REQUEST);
        }

        $categoriaRaw     = strtoupper(trim((string) $request->request->get('categoria', 'PECA')));
        $categoriasValidas = [
            PastaDocumento::CATEGORIA_PECA,
            PastaDocumento::CATEGORIA_PROCURACAO,
            PastaDocumento::CATEGORIA_IDENTIFICACAO,
            PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA,
            PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA,
            PastaDocumento::CATEGORIA_DEMAIS,
            PastaDocumento::CATEGORIA_CONTRATO,
        ];
        $categoria = in_array($categoriaRaw, $categoriasValidas, true) ? $categoriaRaw : PastaDocumento::CATEGORIA_PECA;

        $descricao = trim((string) $request->request->get('descricao', '')) ?: null;
        $numero    = trim((string) $request->request->get('numero', '')) ?: null;

        try {
            $doc = $this->uploadPecaUseCase->executar($pasta, $arquivo, $categoria, $descricao, $numero);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success'   => true,
            'documento' => [
                'id'          => $doc->getId(),
                'titulo'      => $doc->getTitulo(),
                'categoria'   => $doc->getCategoria(),
                'uploadedAt'  => $doc->getUploadedAt()->format('d/m/Y H:i'),
                'viewUrl'     => $this->generateUrl('pasta_documento_view', ['id' => $doc->getId()]),
                'downloadUrl' => $this->generateUrl('pasta_documento_download', ['id' => $doc->getId()]),
                'mimeType'    => $doc->getMimeType(),
            ],
        ]);
    }

    #[Route('/{id}/peticionar/texto', name: 'pasta_peticionar_texto', methods: ['POST'])]
    public function criarTexto(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if (!$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pasta->getId(), 'edit')) {
            return new JsonResponse(['success' => false, 'error' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('peticionar_texto_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'error' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $conteudoHtml = (string) $request->request->get('conteudo', '');
        $titulo       = trim((string) $request->request->get('titulo', ''));

        $categoriaRaw     = strtoupper(trim((string) $request->request->get('categoria', 'PECA')));
        $categoriasValidas = [
            PastaDocumento::CATEGORIA_PECA,
            PastaDocumento::CATEGORIA_PROCURACAO,
            PastaDocumento::CATEGORIA_IDENTIFICACAO,
            PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA,
            PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA,
            PastaDocumento::CATEGORIA_DEMAIS,
            PastaDocumento::CATEGORIA_CONTRATO,
        ];
        $categoria = in_array($categoriaRaw, $categoriasValidas, true) ? $categoriaRaw : PastaDocumento::CATEGORIA_PECA;

        try {
            $doc = $this->salvarPecaTextoUseCase->executar($pasta, $conteudoHtml, $titulo, $categoria);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success'   => true,
            'documento' => [
                'id'          => $doc->getId(),
                'titulo'      => $doc->getTitulo(),
                'categoria'   => $doc->getCategoria(),
                'uploadedAt'  => $doc->getUploadedAt()->format('d/m/Y H:i'),
                'viewUrl'     => $this->generateUrl('pasta_documento_view', ['id' => $doc->getId()]),
                'downloadUrl' => $this->generateUrl('pasta_documento_download', ['id' => $doc->getId()]),
                'mimeType'    => $doc->getMimeType(),
            ],
        ]);
    }

    #[Route('/{id}/peticionar/imagem', name: 'pasta_peticionar_upload_imagem', methods: ['POST'])]
    public function uploadImagemEditor(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if (!$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pasta->getId(), 'edit')) {
            return new JsonResponse(['erro' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('upload_imagem_editor_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return new JsonResponse(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $arquivo = $request->files->get('imagem');
        if ($arquivo === null) {
            return new JsonResponse(['erro' => 'Nenhuma imagem enviada.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $output = $this->uploadImagemEditorUseCase->executar(
                new UploadImagemEditorInput($arquivo, $this->uploadsDir),
            );
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['url' => '/uploads/pastas/' . $output->nomeArquivo]);
    }

    #[Route('/documento/{id}/texto', name: 'pasta_documento_editar_texto', methods: ['PUT'])]
    public function editarTexto(PastaDocumento $doc, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $pasta = $doc->getPasta();
        if ($pasta === null || !$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pasta->getId(), 'edit')) {
            return new JsonResponse(['success' => false, 'error' => 'Sem permissão.'], Response::HTTP_FORBIDDEN);
        }

        if ($doc->getMimeType() !== 'text/html') {
            return new JsonResponse(['success' => false, 'error' => 'Documento não é texto editável.'], Response::HTTP_BAD_REQUEST);
        }

        $requestData  = json_decode($request->getContent(), true) ?? [];
        $csrfToken    = (string) ($requestData['_token'] ?? '');
        $conteudoHtml = (string) ($requestData['conteudo'] ?? '');
        $titulo       = isset($requestData['titulo']) ? (string) $requestData['titulo'] : null;

        if (!$this->isCsrfTokenValid('peticionar_editar_' . $doc->getId(), $csrfToken)) {
            return new JsonResponse(['success' => false, 'error' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $this->editarPecaTextoUseCase->executar($doc, $conteudoHtml, $titulo);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/documento/{id}/exportar/{formato}', name: 'pasta_documento_exportar_texto', methods: ['GET'])]
    public function exportarTexto(PastaDocumento $doc, string $formato): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $pasta = $doc->getPasta();
        if ($pasta === null || !$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pasta->getId(), 'view')) {
            throw $this->createAccessDeniedException();
        }

        if ($doc->getMimeType() !== 'text/html') {
            return new Response('Documento não é um texto editável.', Response::HTTP_BAD_REQUEST);
        }

        $formatosValidos = ['docx', 'pdf', 'odt', 'txt'];
        if (!in_array($formato, $formatosValidos, true)) {
            return new Response('Formato inválido.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $output = $this->exportarPecaTextoUseCase->executar($doc, $formato);
        } catch (\InvalidArgumentException $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $response = new Response($output->conteudo);
        $response->headers->set('Content-Type', $output->mimeType);
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="' . rawurlencode($output->nomeArquivo) . '"',
        );

        return $response;
    }
}
