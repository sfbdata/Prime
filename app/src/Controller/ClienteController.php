<?php

namespace App\Controller;

use App\Entity\Cliente\Cliente;
use App\Entity\Cliente\ClientePF;
use App\Entity\Cliente\ClientePJ;
use App\Entity\Comercial\PreCadastro;
use App\Form\ClientePFType;
use App\Form\ClientePJType;
use App\Repository\ClienteRepository;
use App\Repository\ClientePFRepository;
use App\Repository\ClientePJRepository;
use App\Repository\PastaRepository;
use App\Entity\Permission\AccessRequest;
use App\Repository\AccessRequestRepository;
use App\Repository\PreCadastroRepository;
use App\Service\PermissionChecker;
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
 * - GET  /clientes                           → Lista todos os clientes
 * - GET  /clientes/novo-pf                   → Formulário de criação PF
 * - POST /clientes/novo-pf                   → Cria cliente PF
 * - GET  /clientes/novo-pj                   → Formulário de criação PJ
 * - POST /clientes/novo-pj                   → Cria cliente PJ
 * - GET  /clientes/from-pre-cadastro/{id}    → Cria cliente a partir de pré-cadastro
 * - GET  /clientes/{id}                      → Exibe detalhes do cliente
 * - GET  /clientes/{id}/editar               → Formulário de edição
 * - POST /clientes/{id}/editar               → Atualiza cliente
 * - POST /clientes/{id}/deletar              → Remove cliente
 *
 */
#[Route('/clientes')]
class ClienteController extends AbstractController
{
    #[Route('/', name: 'cliente_index', methods: ['GET'])]
    public function index(Request $request, ClienteRepository $repo, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        if (!$permissionChecker->canAccessModule($currentUser, 'clientes')) {
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
            'clientes' => $hasFilters ? $repo->findByFilters($filters) : $repo->findAll(),
            'filters' => $filters,
            'nomes' => $repo->findAllNomes(),
            'documentos' => $repo->findAllDocumentos(),
            'celulares' => $repo->findAllCelulares(),
        ]);
    }

    #[Route('/novo-pf', name: 'cliente_new_pf', methods: ['GET', 'POST'])]
    public function newPF(Request $request, ClientePFRepository $repo, EntityManagerInterface $em, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        if (!$permissionChecker->canAccessModule($currentUser, 'clientes')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de clientes.');
            return $this->redirectToRoute('homepage');
        }

        $cliente = new ClientePF();
        $form = $this->createForm(ClientePFType::class, $cliente);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cliente->setCriadoPor($this->getUser());
            $repo->save($cliente, true);
            return $this->redirectToRoute('cliente_index');
        }

        return $this->render('cliente/new_pf.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/novo-pj', name: 'cliente_new_pj', methods: ['GET', 'POST'])]
    public function newPJ(Request $request, ClientePJRepository $repo, EntityManagerInterface $em, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        if (!$permissionChecker->canAccessModule($currentUser, 'clientes')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de clientes.');
            return $this->redirectToRoute('homepage');
        }

        $cliente = new ClientePJ();
        $form = $this->createForm(ClientePJType::class, $cliente);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cliente->setCriadoPor($this->getUser());
            $repo->save($cliente, true);
            return $this->redirectToRoute('cliente_index');
        }

        return $this->render('cliente/new_pj.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/from-pre-cadastro/{id}', name: 'cliente_from_pre_cadastro', methods: ['GET', 'POST'])]
    public function fromPreCadastro(Request $request, PreCadastroRepository $preCadastroRepo, ClientePFRepository $clientePFRepo, ClientePJRepository $clientePJRepo, EntityManagerInterface $em, int $id, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        if (!$permissionChecker->canAccessModule($currentUser, 'clientes')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de clientes.');
            return $this->redirectToRoute('homepage');
        }

        $preCadastro = $preCadastroRepo->find($id);

        if (!$preCadastro) {
            throw $this->createNotFoundException('Pré-cadastro não encontrado');
        }

        // Detectar tipo (PF ou PJ baseado no tipo do pré-cadastro)
        $isPF = $preCadastro->getTipo() === 'PF';

        if ($isPF) {
            $cliente = new ClientePF();
            $cliente->setNomeCompleto($preCadastro->getNomeCliente());
            $cliente->setCpf($preCadastro->getCpf());
            $cliente->setTelefoneCelular($preCadastro->getTelefone());
            
            $form = $this->createForm(ClientePFType::class, $cliente);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $cliente->setCriadoPor($this->getUser());
                $clientePFRepo->save($cliente, true);
                $preCadastro->setCliente($cliente);
                $em->persist($preCadastro);
                $em->flush();
                return $this->redirectToRoute('cliente_index');
            }

            return $this->render('cliente/new_pf.html.twig', [
                'form' => $form,
                'preCadastro' => $preCadastro,
            ]);
        } else {
            $cliente = new ClientePJ();
            $cliente->setRazaoSocial($preCadastro->getNomeCliente());
            $cliente->setCnpj($preCadastro->getCpf());
            $cliente->setTelefoneCelular($preCadastro->getTelefone());
            
            $form = $this->createForm(ClientePJType::class, $cliente);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $cliente->setCriadoPor($this->getUser());
                $clientePJRepo->save($cliente, true);
                $preCadastro->setCliente($cliente);
                $em->persist($preCadastro);
                $em->flush();
                return $this->redirectToRoute('cliente_index');
            }

            return $this->render('cliente/new_pj.html.twig', [
                'form' => $form,
                'preCadastro' => $preCadastro,
            ]);
        }
    }

    #[Route('/{id}', name: 'cliente_show', methods: ['GET'])]
    public function show(ClienteRepository $repo, PastaRepository $pastaRepo, int $id, PermissionChecker $permissionChecker, AccessRequestRepository $accessRequestRepo): Response
    {
        $cliente = $repo->find($id);

        if (!$cliente) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        if (!$permissionChecker->canAccessResource($currentUser, 'cliente', $id, 'view')) {
            $hasPending = $accessRequestRepo->findPendingForUserAndResource($currentUser, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_VIEW) !== null;
            $identifier = $cliente instanceof ClientePF ? $cliente->getNomeCompleto() : $cliente->getRazaoSocial();

            return $this->render('access_request/denied.html.twig', [
                'resourceType' => AccessRequest::RESOURCE_CLIENTE,
                'resourceId'   => $id,
                'action'       => AccessRequest::ACTION_VIEW,
                'label'        => 'Cliente',
                'identifier'   => $identifier,
                'hasPending'   => $hasPending,
                'backRoute'    => 'cliente_index',
            ]);
        }

        return $this->render('cliente/show.html.twig', [
            'cliente' => $cliente,
            'pastas' => $pastaRepo->findByCliente($cliente),
        ]);
    }

    #[Route('/{id}/editar', name: 'cliente_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ClienteRepository $repo, EntityManagerInterface $em, int $id, PermissionChecker $permissionChecker, AccessRequestRepository $accessRequestRepo): Response
    {
        $cliente = $repo->find($id);

        if (!$cliente) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        if (!$permissionChecker->canAccessResource($currentUser, 'cliente', $id, 'edit')) {
            $hasPending = $accessRequestRepo->findPendingForUserAndResource($currentUser, AccessRequest::RESOURCE_CLIENTE, $id, AccessRequest::ACTION_EDIT) !== null;
            $identifier = $cliente instanceof ClientePF ? $cliente->getNomeCompleto() : $cliente->getRazaoSocial();

            return $this->render('access_request/denied.html.twig', [
                'resourceType' => AccessRequest::RESOURCE_CLIENTE,
                'resourceId'   => $id,
                'action'       => AccessRequest::ACTION_EDIT,
                'label'        => 'Cliente',
                'identifier'   => $identifier,
                'hasPending'   => $hasPending,
                'backRoute'    => 'cliente_index',
            ]);
        }

        if ($cliente instanceof ClientePF) {
            $form = $this->createForm(ClientePFType::class, $cliente);
        } else {
            $form = $this->createForm(ClientePJType::class, $cliente);
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            return $this->redirectToRoute('cliente_show', ['id' => $cliente->getId()]);
        }

        // Render separate templates for PF and PJ (split views)
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
    public function delete(Request $request, ClienteRepository $repo, EntityManagerInterface $em, int $id, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();

        $cliente = $repo->find($id);

        if (!$cliente) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        if (!$permissionChecker->canAccessResource($currentUser, 'cliente', $id, 'delete')) {
            throw $this->createAccessDeniedException('Você não tem permissão para excluir este cliente.');
        }

        if ($this->isCsrfTokenValid('delete' . $id, $request->request->get('_token'))) {
            try {
                $em->remove($cliente);
                $em->flush();
                $this->addFlash('success', 'Cliente excluído com sucesso.');
            } catch (ForeignKeyConstraintViolationException) {
                $this->addFlash('error', 'Não é possível excluir este cliente porque ele está vinculado a outros registros (ex.: pré-cadastro).');
            }
        }

        return $this->redirectToRoute('cliente_index');
    }

}
