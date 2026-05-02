<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Cliente\Entity\Cliente;
use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaDocumento;
use App\Pasta\UseCase\UploadPecaUseCase;
use App\Service\PermissionChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/pasta')]
final class PeticionarController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly UploadPecaUseCase $uploadPecaUseCase,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
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

        $csrfToken = $this->csrfTokenManager
            ->getToken('peticionar_upload_' . $pasta->getId())
            ->getValue();

        return $this->render('pasta/peticionar.html.twig', [
            'pasta'          => $pasta,
            'documentos'     => $documentos,
            'nomeCliente'    => $nomeCliente,
            'csrfToken'      => $csrfToken,
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
}
