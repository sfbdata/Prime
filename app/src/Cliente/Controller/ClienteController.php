<?php

namespace App\Cliente\Controller;

use App\Controller\Trait\ResourceAccessTrait;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Form\ClientePFType;
use App\Cliente\Form\ClientePJType;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Repository\ClientePFRepository;
use App\Cliente\Repository\ClientePJRepository;
use App\Repository\ClienteDocumentoRepository;
use App\Repository\PastaRepository;
use App\Entity\Permission\AccessRequest;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\ArquivoStorageService;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ClienteController - Gerencia clientes (PF e PJ).
 *
 * Estrutura de rotas REST:
 * - GET  /clientes                                    → Lista todos os clientes
 * - GET  /clientes/novo-pf                            → Formulário de criação PF
 * - POST /clientes/novo-pf                            → Cria cliente PF
 * - GET  /clientes/novo-pj                            → Formulário de criação PJ
 * - POST /clientes/novo-pj                            → Cria cliente PJ
 * - GET  /clientes/from-pre-cadastro/{id}             → Cria cliente a partir de pré-cadastro
 * - GET  /clientes/{id}                               → Exibe detalhes do cliente
 * - GET  /clientes/{id}/editar                        → Formulário de edição
 * - POST /clientes/{id}/editar                        → Atualiza cliente
 * - POST /clientes/{id}/deletar                       → Remove cliente
 * - POST /clientes/{id}/documento/upload              → Faz upload de documento
 * - GET  /clientes/documento/{id}/visualizar          → Visualiza documento inline
 * - GET  /clientes/documento/{id}/download            → Faz download de documento
 * - POST /clientes/documento/{id}/editar              → Edita metadados do documento
 * - POST /clientes/documento/{id}/deletar             → Remove documento
 */
#[Route('/clientes')]
class ClienteController extends AbstractController
{
    use ResourceAccessTrait;

    /** @var array<string, string> */
    private const DOCUMENT_TYPES = [
        ClienteDocumento::CATEGORIA_IDENTIFICACAO          => 'Identificação',
        ClienteDocumento::CATEGORIA_PROCURACAO             => 'Procuração',
        ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => 'Comprovante de residência',
        ClienteDocumento::CATEGORIA_CONTRATO               => 'Contrato',
        ClienteDocumento::CATEGORIA_DEMAIS                 => 'Demais documentos',
    ];

    private const MIME_LIMITS = [
        'image/png'                          => 3 * 1024 * 1024,
        'image/jpeg'                         => 3 * 1024 * 1024,
        'application/pdf'                    => 10 * 1024 * 1024,
        'application/vnd.google-earth.kml+xml' => 5 * 1024 * 1024,
        'application/xml'                    => 5 * 1024 * 1024,
        'text/xml'                           => 5 * 1024 * 1024,
        'application/vnd.ms-excel'                                          => 10 * 1024 * 1024,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 10 * 1024 * 1024,
        'audio/x-opus+ogg'                   => 10 * 1024 * 1024,
        'audio/vorbis'                        => 10 * 1024 * 1024,
        'audio/opus'                          => 10 * 1024 * 1024,
        'audio/mpeg'                          => 10 * 1024 * 1024,
        'audio/ogg'                           => 10 * 1024 * 1024,
        'audio/mp3'                           => 10 * 1024 * 1024,
        'audio/wav'                           => 50 * 1024 * 1024,
        'audio/x-wav'                         => 50 * 1024 * 1024,
        'audio/mp4'                           => 10 * 1024 * 1024,
        'video/x-ms-wmv'                      => 50 * 1024 * 1024,
        'video/mpeg'                          => 50 * 1024 * 1024,
        'video/ogg'                           => 50 * 1024 * 1024,
        'video/quicktime'                     => 50 * 1024 * 1024,
        'video/mp4'                           => 50 * 1024 * 1024,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClienteRepository $clienteRepository,
        private readonly ClientePFRepository $clientePFRepository,
        private readonly ClientePJRepository $clientePJRepository,
        private readonly ClienteDocumentoRepository $clienteDocumentoRepository,
        private readonly PastaRepository $pastaRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly ArquivoStorageService $storage,
        private readonly string $clientesUploadsDir,
    ) {}

    #[Route('/', name: 'cliente_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessModule($currentUser, $tenant, 'clientes')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de clientes.');
            return $this->redirectToRoute('homepage');
        }

        $filters = [
            'nome' => $request->query->get('nome', ''),
            'documento' => $request->query->get('documento', ''),
            'tipo' => $request->query->get('tipo', ''),
            'celular' => $request->query->get('celular', ''),
        ];

        $hasFilters = array_filter($filters, fn($v) => $v !== '');

        return $this->render('cliente/index.html.twig', [
            'clientes' => $hasFilters ? $this->clienteRepository->findByFilters($filters) : $this->clienteRepository->findAll(),
            'filters' => $filters,
            'nomes' => $this->clienteRepository->findAllNomes(),
            'documentos' => $this->clienteRepository->findAllDocumentos(),
            'celulares' => $this->clienteRepository->findAllCelulares(),
        ]);
    }

    #[Route('/novo-pf', name: 'cliente_new_pf', methods: ['GET', 'POST'])]
    public function newPF(Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessModule($currentUser, $tenant, 'clientes')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de clientes.');
            return $this->redirectToRoute('homepage');
        }

        $cliente = new ClientePF();
        $form = $this->createForm(ClientePFType::class, $cliente);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cliente->setCriadoPor($this->getUser());
            $this->clientePFRepository->save($cliente, true);
            return $this->redirectToRoute('cliente_index');
        }

        return $this->render('cliente/new_pf.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/novo-pj', name: 'cliente_new_pj', methods: ['GET', 'POST'])]
    public function newPJ(Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessModule($currentUser, $tenant, 'clientes')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de clientes.');
            return $this->redirectToRoute('homepage');
        }

        $cliente = new ClientePJ();
        $form = $this->createForm(ClientePJType::class, $cliente);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cliente->setCriadoPor($this->getUser());
            $this->clientePJRepository->save($cliente, true);
            return $this->redirectToRoute('cliente_index');
        }

        return $this->render('cliente/new_pj.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'cliente_show', methods: ['GET'])]
    public function show(int $id): Response
    {
        $cliente = $this->clienteRepository->find($id);

        if (!$cliente) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_VIEW, 'cliente_index', $cliente->getNomeExibicao())) {
            return $redirect;
        }

        return $this->render('cliente/show.html.twig', [
            'cliente' => $cliente,
            'pastas' => $this->pastaRepository->findByCliente($cliente),
            'documentTypeOptions' => self::DOCUMENT_TYPES,
            'documentosPorTipo' => $this->groupDocumentsByType($cliente),
        ]);
    }

    #[Route('/{id}/editar', name: 'cliente_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $cliente = $this->clienteRepository->find($id);

        if (!$cliente) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_EDIT, 'cliente_index', $cliente->getNomeExibicao())) {
            return $redirect;
        }

        if ($cliente instanceof ClientePF) {
            $form = $this->createForm(ClientePFType::class, $cliente);
        } else {
            $form = $this->createForm(ClientePJType::class, $cliente);
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            return $this->redirectToRoute('cliente_show', ['id' => $cliente->getId()]);
        }

        if ($cliente instanceof ClientePF) {
            return $this->render('cliente/editPF.html.twig', [
                'form' => $form,
                'cliente' => $cliente,
            ]);
        }

        return $this->render('cliente/editPJ.html.twig', [
            'form' => $form,
            'cliente' => $cliente,
        ]);
    }

    #[Route('/{id}/deletar', name: 'cliente_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        $cliente = $this->clienteRepository->find($id);

        if (!$cliente) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        if ($redirect = $this->denyResourceAccessUnlessGranted($this->permissionChecker, $tenant, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_DELETE, 'cliente_index', $cliente->getNomeExibicao())) {
            return $redirect;
        }

        if ($this->isCsrfTokenValid('delete' . $id, $request->request->get('_token'))) {
            try {
                foreach ($cliente->getDocumentos() as $doc) {
                    $this->storage->excluir($this->storage->caminho($this->clientesUploadsDir, $doc->getCaminhoArquivo()));
                }
                $this->em->remove($cliente);
                $this->em->flush();
                $this->addFlash('success', 'Cliente excluído com sucesso.');
            } catch (ForeignKeyConstraintViolationException) {
                $this->addFlash('error', 'Não é possível excluir este cliente porque ele está vinculado a outros registros (ex.: pré-cadastro).');
            }
        }

        return $this->redirectToRoute('cliente_index');
    }

    // -------------------------------------------------------------------------
    // Documentos
    // -------------------------------------------------------------------------

    #[Route('/{id}/documento/upload', name: 'cliente_documento_upload', methods: ['POST'])]
    public function uploadDocumento(Cliente $cliente, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'cliente', (int) $cliente->getId(), 'edit')) {
            throw $this->createAccessDeniedException('Você não tem permissão para enviar documentos neste cliente.');
        }

        if (!$this->isCsrfTokenValid('upload_documento_cliente_' . $cliente->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile[] $arquivos */
        $arquivos = $request->files->get('arquivos', []);

        if (empty($arquivos)) {
            $this->addFlash('error', 'Nenhum arquivo enviado.');
            return $this->redirectToRoute('cliente_show', ['id' => $cliente->getId()]);
        }

        $categorias = $request->request->all('categorias');
        $descricoes = $request->request->all('descricoes');
        $numeros    = $request->request->all('numeros');

        $erros  = [];
        $salvos = 0;

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

            $categoriaRaw = isset($categorias[$i]) ? strtoupper(trim((string) $categorias[$i])) : ClienteDocumento::CATEGORIA_DEMAIS;
            $categoria    = array_key_exists($categoriaRaw, self::DOCUMENT_TYPES) ? $categoriaRaw : ClienteDocumento::CATEGORIA_DEMAIS;
            $descricao    = isset($descricoes[$i]) ? trim((string) $descricoes[$i]) : '';
            $numero       = isset($numeros[$i]) ? trim((string) $numeros[$i]) : '';

            $nomeUnico = $this->storage->salvar($file, $this->clientesUploadsDir);

            $doc = new ClienteDocumento();
            $doc->setCliente($cliente);
            $doc->setTitulo($file->getClientOriginalName());
            $doc->setCategoria($categoria);
            $doc->setDescricao($descricao !== '' ? $descricao : null);
            $doc->setNumero($numero !== '' ? $numero : null);
            $doc->setCaminhoArquivo($nomeUnico);
            $doc->setNomeOriginal($file->getClientOriginalName());
            $doc->setMimeType($mimeType);
            $doc->setTamanhoBytes($tamanho);

            $this->em->persist($doc);
            ++$salvos;
        }

        $this->em->flush();

        foreach ($erros as $erro) {
            $this->addFlash('error', $erro);
        }

        if ($salvos > 0) {
            $this->addFlash('success', sprintf(
                '%d documento%s enviado%s com sucesso.',
                $salvos,
                $salvos > 1 ? 's' : '',
                $salvos > 1 ? 's' : ''
            ));
        }

        return $this->redirectToRoute('cliente_show', ['id' => $cliente->getId()]);
    }

    #[Route('/documento/{id}/visualizar', name: 'cliente_documento_view', methods: ['GET'])]
    public function viewDocumento(ClienteDocumento $doc): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        $clienteId = (int) $doc->getCliente()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'cliente', $clienteId, 'view')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar documentos deste cliente.');
        }

        $caminho = $this->storage->caminho($this->clientesUploadsDir, $doc->getCaminhoArquivo());

        if (!$this->storage->existe($caminho)) {
            throw $this->createNotFoundException('Arquivo não encontrado no servidor.');
        }

        return $this->storage->servir($caminho, $doc->getNomeOriginal(), inline: true);
    }

    #[Route('/documento/{id}/download', name: 'cliente_documento_download', methods: ['GET'])]
    public function downloadDocumento(ClienteDocumento $doc): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        $clienteId = (int) $doc->getCliente()?->getId();
        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'cliente', $clienteId, 'view')) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar documentos deste cliente.');
        }

        $caminho = $this->storage->caminho($this->clientesUploadsDir, $doc->getCaminhoArquivo());

        if (!$this->storage->existe($caminho)) {
            $this->addFlash('error', 'Arquivo não encontrado no servidor.');
            return $this->redirectToRoute('cliente_show', ['id' => $doc->getCliente()?->getId()]);
        }

        return $this->storage->servir($caminho, $doc->getNomeOriginal(), inline: false);
    }

    #[Route('/documento/{id}/editar', name: 'cliente_documento_edit', methods: ['POST'])]
    public function editDocumento(ClienteDocumento $doc, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        $clienteForCheck = $doc->getCliente();
        if ($clienteForCheck !== null && !$this->permissionChecker->canAccessResource($currentUser, $tenant, 'cliente', (int) $clienteForCheck->getId(), 'edit')) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar documentos deste cliente.');
        }

        if (!$this->isCsrfTokenValid('edit_doc_cliente_' . $doc->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $categoriaRaw = strtoupper(trim((string) $request->request->get('categoria', '')));
        $categoria    = array_key_exists($categoriaRaw, self::DOCUMENT_TYPES) ? $categoriaRaw : $doc->getCategoria();

        $descricao = trim((string) $request->request->get('descricao', ''));
        $numero    = trim((string) $request->request->get('numero', ''));
        $nomeBase  = trim((string) $request->request->get('nomeBase', ''));

        $doc->setCategoria($categoria);
        $doc->setDescricao($descricao !== '' ? $descricao : null);
        $doc->setNumero($numero !== '' ? $numero : null);
        if ($nomeBase !== '') {
            $extensao = pathinfo($doc->getNomeOriginal(), PATHINFO_EXTENSION);
            $nomeComExtensao = $nomeBase . ($extensao !== '' ? '.' . $extensao : '');
            $doc->setNomeOriginal($nomeComExtensao);
        }

        $this->em->flush();

        $this->addFlash('success', 'Documento atualizado com sucesso.');

        return $this->redirectToRoute('cliente_show', ['id' => $doc->getCliente()?->getId()]);
    }

    #[Route('/documento/{id}/deletar', name: 'cliente_documento_delete', methods: ['POST'])]
    public function deleteDocumento(ClienteDocumento $doc, Request $request): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        $clienteForCheck = $doc->getCliente();
        if ($clienteForCheck !== null && !$this->permissionChecker->canAccessResource($currentUser, $tenant, 'cliente', (int) $clienteForCheck->getId(), 'edit')) {
            throw $this->createAccessDeniedException('Você não tem permissão para remover documentos deste cliente.');
        }

        $clienteId = $doc->getCliente()?->getId();

        if (!$this->isCsrfTokenValid('delete_doc_cliente_' . $doc->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $this->storage->excluir($this->storage->caminho($this->clientesUploadsDir, $doc->getCaminhoArquivo()));
        $this->em->remove($doc);
        $this->em->flush();

        $this->addFlash('success', 'Documento removido com sucesso.');

        return $this->redirectToRoute('cliente_show', ['id' => $clienteId]);
    }

    /**
     * @return array<string, ClienteDocumento[]>
     */
    private function groupDocumentsByType(Cliente $cliente): array
    {
        $grouped = [];
        foreach (array_keys(self::DOCUMENT_TYPES) as $tipo) {
            $grouped[$tipo] = [];
        }
        foreach ($cliente->getDocumentos() as $doc) {
            $cat = $doc->getCategoria();
            if (array_key_exists($cat, $grouped)) {
                $grouped[$cat][] = $doc;
            }
        }
        return $grouped;
    }
}
