<?php

namespace App\Controller;

use App\Controller\Trait\ResourceAccessTrait;
use App\Entity\Auth\User;
use App\Entity\Cliente\Cliente;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\ParteContraria;
use App\Entity\Pasta\PastaDocumento;
use App\Entity\Processo\Processo;
use App\Repository\ClienteRepository;
use App\Repository\PastaDocumentoRepository;
use App\Repository\PastaRepository;
use App\Repository\ProcessoRepository;
use App\Entity\Permission\AccessRequest;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * PastaController - Gerencia pastas de clientes.
 *
 * Estrutura de rotas:
 * - GET  /pasta              → Lista todas as pastas
 * - GET  /pasta/nova         → Formulário de criação
 * - POST /pasta/nova         → Cria nova pasta
 * - GET  /pasta/{id}                         → Exibe detalhes da pasta
 * - GET  /pasta/{id}/editar                  → Formulário de edição
 * - POST /pasta/{id}/editar                  → Atualiza pasta
 * - POST /pasta/{id}/deletar                 → Remove pasta
 * - POST /pasta/{id}/documento/upload        → Faz upload de documento
 * - GET  /pasta/documento/{id}/download      → Faz download de documento
 * - POST /pasta/documento/{id}/deletar       → Remove documento
 */
#[Route('/pasta')]
class PastaController extends AbstractController
{
    use ResourceAccessTrait;
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaRepository $pastaRepository,
        private readonly PastaDocumentoRepository $pastaDocumentoRepository,
        private readonly ProcessoRepository $processoRepository,
        private readonly ClienteRepository $clienteRepository,
        private readonly UserRepository $userRepository,
        private readonly ValidatorInterface $validator,
        private readonly string $uploadsDir,
        private readonly PermissionChecker $permissionChecker,
    ) {}

    #[Route('', name: 'pasta_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        if (!$this->permissionChecker->canAccessModule($currentUser, 'pastas')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de pastas.');
            return $this->redirectToRoute('homepage');
        }

        return $this->render('pasta/index.html.twig', [
            'pastas' => $this->pastaRepository->findAll(),
        ]);
    }

    #[Route('/nova', name: 'pasta_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        if (!$this->permissionChecker->canAccessModule($currentUser, 'pastas')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de pastas.');
            return $this->redirectToRoute('homepage');
        }

        $pasta = new Pasta();

        if ($request->isMethod('POST')) {
            $errors = $this->fillPastaFromRequest($pasta, $request->request->all());

            if ($errors === []) {
                $violations = $this->validator->validate($pasta);

                if (count($violations) > 0) {
                    foreach ($violations as $violation) {
                        $this->addFlash('error', $violation->getMessage());
                    }
                } else {
                    $pasta->setCriadoPor($this->getUser());
                    $this->em->persist($pasta);
                    $this->em->flush();
                    $this->addFlash('success', 'Pasta criada com sucesso.');

                    return $this->redirectToRoute('pasta_index');
                }
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
            }
        }

        return $this->render('pasta/new.html.twig', [
            'pasta' => $pasta,
            'processos' => $this->processoRepository->findAll(),
            'clientes' => $this->clienteRepository->findAll(),
            'usuarios' => $this->userRepository->findBy(['isActive' => true], ['fullName' => 'ASC']),
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}', name: 'pasta_show', methods: ['GET'])]
    public function show(Pasta $pasta): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        return $this->render('pasta/show.html.twig', [
            'pasta' => $pasta,
            'documentTypeOptions' => self::DOCUMENT_TYPES,
            'documentosPorTipo' => $this->groupDocumentsByType($pasta),
        ]);
    }

    #[Route('/{id}/editar', name: 'pasta_edit', methods: ['GET', 'POST'])]
    public function edit(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        if ($request->isMethod('POST')) {
            $errors = $this->fillPastaFromRequest($pasta, $request->request->all());

            if ($errors === []) {
                $violations = $this->validator->validate($pasta);

                if (count($violations) > 0) {
                    foreach ($violations as $violation) {
                        $this->addFlash('error', $violation->getMessage());
                    }
                } else {
                    $this->em->flush();
                    $this->addFlash('success', 'Pasta atualizada com sucesso.');

                    return $this->redirectToRoute('pasta_index');
                }
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
            }
        }

        return $this->render('pasta/new.html.twig', [
            'pasta' => $pasta,
            'processos' => $this->processoRepository->findAll(),
            'clientes' => $this->clienteRepository->findAll(),
            'usuarios' => $this->userRepository->findBy(['isActive' => true], ['fullName' => 'ASC']),
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}/deletar', name: 'pasta_delete', methods: ['POST'])]
    public function delete(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $pastaId = (int) $pasta->getId();
        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_DELETE, 'pasta_index', $pasta->getNup() ?? '#' . $pastaId)) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('delete_pasta_'.$pasta->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        foreach ($pasta->getDocumentos() as $doc) {
            $caminho = $this->uploadsDir . '/' . $doc->getCaminhoArquivo();
            if (file_exists($caminho)) {
                unlink($caminho);
            }
        }

        $this->em->remove($pasta);
        $this->em->flush();

        $this->addFlash('success', 'Pasta removida com sucesso.');

        return $this->redirectToRoute('pasta_index');
    }

    // -------------------------------------------------------------------------
    // Documentos
    // -------------------------------------------------------------------------

    /** @var array<string, string> */
    private const DOCUMENT_TYPES = [
        PastaDocumento::CATEGORIA_PECA                  => 'Peça',
        PastaDocumento::CATEGORIA_PROCURACAO            => 'Procuração',
        PastaDocumento::CATEGORIA_IDENTIFICACAO         => 'Identificação',
        PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => 'Comprovante de residência',
        PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA    => 'Gratuidade de justiça',
        PastaDocumento::CATEGORIA_DEMAIS                => 'Demais documentos',
    ];

    private const MIME_LIMITS = [
        // Imagens
        'image/png'                          => 3 * 1024 * 1024,
        'image/jpeg'                         => 3 * 1024 * 1024,
        // Documentos
        'application/pdf'                    => 10 * 1024 * 1024,
        'application/vnd.google-earth.kml+xml' => 5 * 1024 * 1024,
        // Áudio
        'audio/x-opus+ogg'                   => 10 * 1024 * 1024,
        'audio/vorbis'                        => 10 * 1024 * 1024,
        'audio/opus'                          => 10 * 1024 * 1024,
        'audio/mpeg'                          => 10 * 1024 * 1024,
        'audio/ogg'                           => 10 * 1024 * 1024,
        'audio/mp3'                           => 10 * 1024 * 1024,
        'audio/wav'                           => 50 * 1024 * 1024,
        'audio/x-wav'                         => 50 * 1024 * 1024,
        'audio/mp4'                           => 10 * 1024 * 1024,
        // Vídeo
        'video/x-ms-wmv'                      => 50 * 1024 * 1024,
        'video/mpeg'                          => 50 * 1024 * 1024,
        'video/ogg'                           => 50 * 1024 * 1024,
        'video/quicktime'                     => 50 * 1024 * 1024,
        'video/mp4'                           => 50 * 1024 * 1024,
    ];

    #[Route('/{id}/documento/upload', name: 'pasta_documento_upload', methods: ['POST'])]
    public function uploadDocumento(Pasta $pasta, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        if (!$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pasta->getId(), 'edit')) {
            throw $this->createAccessDeniedException('Você não tem permissão para enviar documentos nesta pasta.');
        }

        if (!$this->isCsrfTokenValid('upload_documento_pasta_'.$pasta->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile[] $arquivos */
        $arquivos = $request->files->get('arquivos', []);

        if (empty($arquivos)) {
            $this->addFlash('error', 'Nenhum arquivo enviado.');

            return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
        }

        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }

        $categorias  = $request->request->all('categorias');
        $descricoes  = $request->request->all('descricoes');
        $numeros     = $request->request->all('numeros');

        $erros    = [];
        $salvos   = 0;

        foreach ($arquivos as $i => $file) {
            if ($file === null) {
                continue;
            }

            $mimeType = $file->getMimeType() ?? '';

            if (!array_key_exists($mimeType, self::MIME_LIMITS)) {
                $erros[] = sprintf('"%s": tipo não permitido (%s).', $file->getClientOriginalName(), $mimeType);
                continue;
            }

            $tamanho = $file->getSize();
            $limite  = self::MIME_LIMITS[$mimeType];

            if ($tamanho > $limite) {
                $erros[] = sprintf(
                    '"%s": excede o limite de %d MB.',
                    $file->getClientOriginalName(),
                    intdiv($limite, 1024 * 1024)
                );
                continue;
            }

            $categoriaRaw = isset($categorias[$i]) ? strtoupper(trim((string) $categorias[$i])) : PastaDocumento::CATEGORIA_DEMAIS;
            $categoria    = array_key_exists($categoriaRaw, self::DOCUMENT_TYPES) ? $categoriaRaw : PastaDocumento::CATEGORIA_DEMAIS;
            $descricao    = isset($descricoes[$i]) ? trim((string) $descricoes[$i]) : '';
            $numero       = isset($numeros[$i]) ? trim((string) $numeros[$i]) : '';

            $nomeUnico = bin2hex(random_bytes(16)) . '.' . $file->guessExtension();
            $file->move($this->uploadsDir, $nomeUnico);

            $doc = new PastaDocumento();
            $doc->setPasta($pasta);
            $doc->setTitulo($file->getClientOriginalName());
            $doc->setCategoria($categoria);
            $doc->setDescricao($descricao !== '' ? $descricao : null);
            $doc->setNumero($numero !== '' ? $numero : null);
            $doc->setCaminhoArquivo($nomeUnico);
            $doc->setNomeOriginal($file->getClientOriginalName());
            $doc->setMimeType($mimeType);
            $doc->setTamanhoBytes($tamanho);

            $this->em->persist($doc);
            $this->markChecklistByCategoria($pasta, $categoria);
            ++$salvos;
        }

        $this->em->flush();

        if ($erros) {
            foreach ($erros as $erro) {
                $this->addFlash('error', $erro);
            }
        }

        if ($salvos > 0) {
            $this->addFlash('success', sprintf(
                '%d documento%s enviado%s com sucesso.',
                $salvos,
                $salvos > 1 ? 's' : '',
                $salvos > 1 ? 's' : ''
            ));
        }

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
    }

    #[Route('/documento/{id}/visualizar', name: 'pasta_documento_view', methods: ['GET'])]
    public function viewDocumento(PastaDocumento $doc): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $doc->getPasta()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, 'pasta', $pastaId, 'view')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar documentos desta pasta.');
        }

        $caminho = $this->uploadsDir . '/' . $doc->getCaminhoArquivo();

        if (!file_exists($caminho)) {
            throw $this->createNotFoundException('Arquivo não encontrado no servidor.');
        }

        $response = new BinaryFileResponse($caminho);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $doc->getNomeOriginal()
        );

        return $response;
    }

    #[Route('/documento/{id}/download', name: 'pasta_documento_download', methods: ['GET'])]
    public function downloadDocumento(PastaDocumento $doc): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaId = (int) $doc->getPasta()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, 'pasta', $pastaId, 'view')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar documentos desta pasta.');
        }

        $caminho = $this->uploadsDir . '/' . $doc->getCaminhoArquivo();

        if (!file_exists($caminho)) {
            $this->addFlash('error', 'Arquivo não encontrado no servidor.');

            return $this->redirectToRoute('pasta_show', ['id' => $doc->getPasta()?->getId()]);
        }

        $response = new BinaryFileResponse($caminho);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $doc->getNomeOriginal()
        );

        return $response;
    }

    #[Route('/documento/{id}/editar', name: 'pasta_documento_edit', methods: ['POST'])]
    public function editDocumento(PastaDocumento $doc, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaForCheck = $doc->getPasta();
        if ($pastaForCheck !== null && !$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pastaForCheck->getId(), 'edit')) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar documentos desta pasta.');
        }

        if (!$this->isCsrfTokenValid('edit_documento_'.$doc->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $categoriaRaw = strtoupper(trim((string) $request->request->get('categoria', '')));
        $categoria    = array_key_exists($categoriaRaw, self::DOCUMENT_TYPES) ? $categoriaRaw : $doc->getCategoria();

        $descricao = trim((string) $request->request->get('descricao', ''));
        $numero    = trim((string) $request->request->get('numero', ''));

        $categoriaAnterior = $doc->getCategoria();
        $doc->setCategoria($categoria);
        $doc->setDescricao($descricao !== '' ? $descricao : null);
        $doc->setNumero($numero !== '' ? $numero : null);

        $pasta = $doc->getPasta();
        if ($pasta !== null && $categoriaAnterior !== $categoria) {
            $this->recalculateChecklistAfterDelete($pasta, $categoriaAnterior);
            $this->markChecklistByCategoria($pasta, $categoria);
        }

        $this->em->flush();

        $this->addFlash('success', 'Documento atualizado com sucesso.');

        return $this->redirectToRoute('pasta_show', ['id' => $doc->getPasta()?->getId()]);
    }

    #[Route('/documento/{id}/deletar', name: 'pasta_documento_delete', methods: ['POST'])]
    public function deleteDocumento(PastaDocumento $doc, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $pastaForCheck = $doc->getPasta();
        if ($pastaForCheck !== null && !$this->permissionChecker->canAccessResource($currentUser, 'pasta', (int) $pastaForCheck->getId(), 'edit')) {
            throw $this->createAccessDeniedException('Você não tem permissão para remover documentos desta pasta.');
        }

        $pastaId = $doc->getPasta()?->getId();

        if (!$this->isCsrfTokenValid('delete_documento_'.$doc->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $caminho = $this->uploadsDir . '/' . $doc->getCaminhoArquivo();
        if (file_exists($caminho)) {
            unlink($caminho);
        }

        $pasta = $doc->getPasta();
        $categoria = $doc->getCategoria();
        $this->em->remove($doc);

        if ($pasta !== null) {
            $this->recalculateChecklistAfterDelete($pasta, $categoria);
        }

        $this->em->flush();

        $this->addFlash('success', 'Documento removido com sucesso.');

        return $this->redirectToRoute('pasta_show', ['id' => $pastaId]);
    }

    private function markChecklistByCategoria(Pasta $pasta, string $categoria): void
    {
        match ($categoria) {
            PastaDocumento::CATEGORIA_PECA                   => $pasta->setDocPecaOk(true),
            PastaDocumento::CATEGORIA_PROCURACAO             => $pasta->setDocProcuracaoOk(true),
            PastaDocumento::CATEGORIA_IDENTIFICACAO          => $pasta->setDocIdentificacaoOk(true),
            PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => $pasta->setDocComprovanteResidenciaOk(true),
            PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA     => $pasta->setDocGratuidadeJusticaOk(true),
            PastaDocumento::CATEGORIA_DEMAIS                 => $pasta->setDocDemaisOk(true),
            default => null,
        };
    }

    private function recalculateChecklistAfterDelete(Pasta $pasta, string $categoriaRemovida): void
    {
        $remaining = $pasta->getDocumentos()->filter(
            fn(PastaDocumento $d) => $d->getCategoria() === $categoriaRemovida
        );

        if ($remaining->count() <= 1) {
            match ($categoriaRemovida) {
                PastaDocumento::CATEGORIA_PECA                   => $pasta->setDocPecaOk(false),
                PastaDocumento::CATEGORIA_PROCURACAO             => $pasta->setDocProcuracaoOk(false),
                PastaDocumento::CATEGORIA_IDENTIFICACAO          => $pasta->setDocIdentificacaoOk(false),
                PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => $pasta->setDocComprovanteResidenciaOk(false),
                PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA     => $pasta->setDocGratuidadeJusticaOk(false),
                PastaDocumento::CATEGORIA_DEMAIS                 => $pasta->setDocDemaisOk(false),
                default => null,
            };
        }
    }

    /**
     * @return array<string, PastaDocumento[]>
     */
    private function groupDocumentsByType(Pasta $pasta): array
    {
        $grouped = [];
        foreach (array_keys(self::DOCUMENT_TYPES) as $tipo) {
            $grouped[$tipo] = [];
        }
        foreach ($pasta->getDocumentos() as $doc) {
            $cat = $doc->getCategoria();
            if (array_key_exists($cat, $grouped)) {
                $grouped[$cat][] = $doc;
            }
        }
        return $grouped;
    }

    /**
     * Preenche a entidade Pasta com os dados do request e retorna lista de erros de validação manual.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function fillPastaFromRequest(Pasta $pasta, array $data): array
    {
        $errors = [];

        $nup = trim((string) ($data['nup'] ?? ''));
        if ($nup === '') {
            $errors[] = 'O NUP é obrigatório.';
        } else {
            $existente = $this->pastaRepository->findOneBy(['nup' => $nup]);
            if ($existente !== null && $existente->getId() !== $pasta->getId()) {
                $errors[] = sprintf('O NUP "%s" já está em uso por outra pasta.', $nup);
            } else {
                $pasta->setNup($nup);
            }
        }

        $status = (string) ($data['status'] ?? Pasta::STATUS_ATIVO);
        if (!in_array($status, [Pasta::STATUS_ATIVO, Pasta::STATUS_ARQUIVADO], true)) {
            $errors[] = 'Status inválido. Valores aceitos: ativo, arquivado.';
        } else {
            $pasta->setStatus($status);
        }

        $dataAberturaStr = trim((string) ($data['dataAbertura'] ?? ''));
        if ($dataAberturaStr !== '') {
            $dataAbertura = \DateTimeImmutable::createFromFormat('Y-m-d', $dataAberturaStr);
            if ($dataAbertura === false) {
                $errors[] = 'Data de abertura inválida. Use o formato AAAA-MM-DD.';
            } else {
                $pasta->setDataAbertura($dataAbertura);
            }
        }

        $descricao = trim((string) ($data['descricao'] ?? ''));
        $pasta->setDescricao($descricao !== '' ? $descricao : null);

        $processoId = (int) ($data['processo_id'] ?? 0);
        if ($processoId > 0) {
            $processo = $this->processoRepository->find($processoId);
            if ($processo instanceof Processo) {
                $pasta->setProcesso($processo);
            } else {
                $errors[] = 'Processo não encontrado.';
            }
        } else {
            // Nenhum processo selecionado → deixa null
            $pasta->setProcesso(null);
        }


        $responsavelId = (int) ($data['responsavel_id'] ?? 0);
        if ($responsavelId > 0) {
            $responsavel = $this->userRepository->find($responsavelId);
            $pasta->setResponsavel($responsavel instanceof User ? $responsavel : null);
        } else {
            $pasta->setResponsavel(null);
        }

        $this->syncClientes($pasta, is_array($data['clientes'] ?? null) ? $data['clientes'] : []);
        $this->syncPartesContrarias($pasta, is_array($data['partes_contrarias'] ?? null) ? $data['partes_contrarias'] : []);

        return $errors;
    }

    /**
     * @param list<int|string> $clienteIds
     */
    private function syncClientes(Pasta $pasta, array $clienteIds): void
    {
        foreach ($pasta->getClientes()->toArray() as $clienteExistente) {
            $pasta->removeCliente($clienteExistente);
        }

        foreach ($clienteIds as $clienteId) {
            $cliente = $this->clienteRepository->find((int) $clienteId);
            if ($cliente instanceof Cliente) {
                $pasta->addCliente($cliente);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $partesData
     */
    private function syncPartesContrarias(Pasta $pasta, array $partesData): void
    {
        $existingById = [];
        foreach ($pasta->getPartesContrarias() as $parte) {
            $id = $parte->getId();
            if ($id !== null) {
                $existingById[(string) $id] = $parte;
            }
        }

        $kept = [];

        foreach ($partesData as $parteData) {
            $nome = trim((string) ($parteData['nome'] ?? ''));
            if ($nome === '') {
                continue;
            }

            $id = trim((string) ($parteData['id'] ?? ''));
            $parte = ($id !== '' && isset($existingById[$id])) ? $existingById[$id] : new ParteContraria();

            if (!$pasta->getPartesContrarias()->contains($parte)) {
                $pasta->addParteContraria($parte);
            }

            if ($parte->getNome() !== $nome) {
                $parte->setNome($nome);
            }

            $kept[spl_object_id($parte)] = true;
        }

        foreach ($pasta->getPartesContrarias()->toArray() as $parteExistente) {
            if (!isset($kept[spl_object_id($parteExistente)])) {
                $pasta->removeParteContraria($parteExistente);
            }
        }
    }
}
