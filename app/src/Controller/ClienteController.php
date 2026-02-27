<?php

namespace App\Controller;

use App\Entity\Cliente\Cliente;
use App\Entity\Cliente\ClientePF;
use App\Entity\Cliente\ClientePJ;
use App\Entity\Contrato\Contrato;
use App\Entity\Comercial\PreCadastro;
use App\Form\ClientePFType;
use App\Form\ClientePJType;
use App\Repository\ClienteRepository;
use App\Repository\ClientePFRepository;
use App\Repository\ClientePJRepository;
use App\Repository\PreCadastroRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cliente')]
class ClienteController extends AbstractController
{
    #[Route('/', name: 'cliente_index', methods: ['GET'])]
    public function index(ClienteRepository $repo): Response
    {
        return $this->render('cliente/index.html.twig', [
            'clientes' => $repo->findAll(),
        ]);
    }

    #[Route('/novo-pf', name: 'cliente_new_pf', methods: ['GET', 'POST'])]
    public function newPF(Request $request, ClientePFRepository $repo, EntityManagerInterface $em): Response
    {
        $cliente = new ClientePF();
        $form = $this->createForm(ClientePFType::class, $cliente);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $uploadedFile = $form->get('contratoFile')->getData();
            $dataInicio = $form->get('contratoDataInicio')->getData();
            $valorTotal = $form->get('contratoValorTotal')->getData();
            $this->handleContratoUpload($uploadedFile, $cliente, $dataInicio, $valorTotal);
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
            $uploadedFile = $form->get('contratoFile')->getData();
            $dataInicio = $form->get('contratoDataInicio')->getData();
            $valorTotal = $form->get('contratoValorTotal')->getData();
            $this->handleContratoUpload($uploadedFile, $cliente, $dataInicio, $valorTotal);
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
                $uploadedFile = $form->get('contratoFile')->getData();
                $dataInicio = $form->get('contratoDataInicio')->getData();
                $valorTotal = $form->get('contratoValorTotal')->getData();
                $this->handleContratoUpload($uploadedFile, $cliente, $dataInicio, $valorTotal);
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
                $uploadedFile = $form->get('contratoFile')->getData();
                $dataInicio = $form->get('contratoDataInicio')->getData();
                $valorTotal = $form->get('contratoValorTotal')->getData();
                $this->handleContratoUpload($uploadedFile, $cliente, $dataInicio, $valorTotal);
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
            $uploadedFile = $form->get('contratoFile')->getData();
            $dataInicio = $form->get('contratoDataInicio')->getData();
            $valorTotal = $form->get('contratoValorTotal')->getData();
            $this->handleContratoUpload($uploadedFile, $cliente, $dataInicio, $valorTotal);
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

    private function handleContratoUpload(?UploadedFile $uploadedFile, Cliente $cliente, ?\DateTimeInterface $dataInicio, mixed $valorTotal): void
    {
        if (!$uploadedFile instanceof UploadedFile) {
            return;
        }

        $uploadsDir = $this->getParameter('kernel.project_dir').'/public/uploads/contratos';
        if (!is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0755, true);
        }

        $newFilename = uniqid('contrato_').'.'.$uploadedFile->guessExtension();

        try {
            $uploadedFile->move($uploadsDir, $newFilename);
            $contrato = new Contrato();
            $contrato->setNomeArquivo($uploadedFile->getClientOriginalName());
            $contrato->setCaminhoArquivo('/uploads/contratos/'.$newFilename);
            $contrato->setStatus(Contrato::STATUS_ATIVO);
            if ($dataInicio !== null) {
                $contrato->setDataInicio(\DateTimeImmutable::createFromInterface($dataInicio));
            }
            if ($valorTotal !== null && $valorTotal !== '') {
                $contrato->setValorTotal(number_format((float) $valorTotal, 2, '.', ''));
            }
            $cliente->addContrato($contrato);
        } catch (FileException $e) {
            // ignore move errors for now
        }
    }
}
