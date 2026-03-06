<?php

namespace App\Controller;

use App\Entity\Cliente\Cliente;
use App\Entity\Cliente\ClientePF;
use App\Entity\Cliente\ClientePJ;
use App\Entity\Contrato\Contrato;
use App\Entity\Comercial\PreCadastro;
use App\Form\ClientePFType;
use App\Form\ClientePJType;
use App\Form\ContratoType;
use App\Repository\ClienteRepository;
use App\Repository\ClientePFRepository;
use App\Repository\ClientePJRepository;
use App\Repository\PreCadastroRepository;
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
 * Rotas aninhadas por contexto (opcional):
 * - GET  /clientes/{clienteId}/contratos     → Lista contratos do cliente
 */
#[Route('/clientes')]
class ClienteController extends AbstractController
{
    #[Route('/', name: 'cliente_index', methods: ['GET'])]
    public function index(Request $request, ClienteRepository $repo): Response
    {
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
    public function newPF(Request $request, ClientePFRepository $repo, EntityManagerInterface $em): Response
    {
        $cliente = new ClientePF();
        $form = $this->createForm(ClientePFType::class, $cliente);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $repo->save($cliente, true);
            return $this->redirectToRoute('cliente_index');
        }

        return $this->render('cliente/new_pf.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/novo-pj', name: 'cliente_new_pj', methods: ['GET', 'POST'])]
    public function newPJ(Request $request, ClientePJRepository $repo, EntityManagerInterface $em): Response
    {
        $cliente = new ClientePJ();
        $form = $this->createForm(ClientePJType::class, $cliente);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $repo->save($cliente, true);
            return $this->redirectToRoute('cliente_index');
        }

        return $this->render('cliente/new_pj.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/from-pre-cadastro/{id}', name: 'cliente_from_pre_cadastro', methods: ['GET', 'POST'])]
    public function fromPreCadastro(Request $request, PreCadastroRepository $preCadastroRepo, ClientePFRepository $clientePFRepo, ClientePJRepository $clientePJRepo, EntityManagerInterface $em, int $id): Response
    {
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
    public function show(ClienteRepository $repo, int $id): Response
    {
        $cliente = $repo->find($id);

        if (!$cliente) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        return $this->render('cliente/show.html.twig', [
            'cliente' => $cliente,
        ]);
    }

    #[Route('/{id}/editar', name: 'cliente_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ClienteRepository $repo, EntityManagerInterface $em, int $id): Response
    {
        $cliente = $repo->find($id);

        if (!$cliente) {
            throw $this->createNotFoundException('Cliente não encontrado');
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
    public function delete(Request $request, ClienteRepository $repo, EntityManagerInterface $em, int $id): Response
    {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException('Você precisa estar logado.');
        }

        if (!(in_array('ROLE_ADMIN', $currentUser->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true))) {
            throw $this->createAccessDeniedException('Apenas usuários com perfil ADMIN podem excluir clientes.');
        }

        $cliente = $repo->find($id);

        if (!$cliente) {
            throw $this->createNotFoundException('Cliente não encontrado');
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

    #[Route('/{clienteId}/contrato/{contratoId}/desvincular', name: 'cliente_contrato_unlink', methods: ['POST'])]
    public function unlinkContrato(Request $request, ClienteRepository $clienteRepo, EntityManagerInterface $em, int $clienteId, int $contratoId): Response
    {
        $cliente = $clienteRepo->find($clienteId);

        if (!$cliente) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        $contratoToRemove = null;
        foreach ($cliente->getContratos() as $contrato) {
            if ($contrato->getId() === $contratoId) {
                $contratoToRemove = $contrato;
                break;
            }
        }

        if (!$contratoToRemove) {
            throw $this->createNotFoundException('Contrato não encontrado');
        }

        if ($this->isCsrfTokenValid('unlink_contrato' . $contratoId, $request->request->get('_token'))) {
            $cliente->removeContrato($contratoToRemove);
            $em->flush();
            $this->addFlash('success', 'Vínculo com contrato removido com sucesso.');
        }

        return $this->redirectToRoute('cliente_edit', ['id' => $clienteId]);
    }

    #[Route('/{id}/contrato/novo', name: 'cliente_contrato_new', methods: ['GET', 'POST'])]
    public function newContrato(Request $request, ClienteRepository $clienteRepo, EntityManagerInterface $em, int $id): Response
    {
        $cliente = $clienteRepo->find($id);

        if (!$cliente) {
            throw $this->createNotFoundException('Cliente não encontrado');
        }

        $contrato = new Contrato();
        $form = $this->createForm(ContratoType::class, $contrato);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cliente->addContrato($contrato);
            $em->persist($contrato);
            $em->flush();
            $this->addFlash('success', 'Contrato criado com sucesso.');
            return $this->redirectToRoute('cliente_show', ['id' => $id]);
        }

        return $this->render('cliente/contrato_new.html.twig', [
            'form' => $form,
            'cliente' => $cliente,
        ]);
    }
}
