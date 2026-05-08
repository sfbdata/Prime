<?php

namespace App\Controller;

use App\Entity\Comercial\PreCadastro;
use App\Form\PreCadastroType;
use App\Repository\PreCadastroRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/pre-cadastro')]
class PreCadastroController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/', name: 'pre_cadastro_index', methods: ['GET'])]
    public function index(PreCadastroRepository $repo, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$permissionChecker->canAccessModule($usuario, $tenant, 'precadastros')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de pré-cadastros.');
            return $this->redirectToRoute('homepage');
        }

        return $this->render('pre_cadastro/index.html.twig', [
            'pre_cadastros' => $repo->findAll(),
        ]);
    }

    #[Route('/new', name: 'pre_cadastro_new', methods: ['GET','POST'])]
    public function new(Request $request, PreCadastroRepository $repo, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$permissionChecker->canAccessModule($usuario, $tenant, 'precadastros')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de pré-cadastros.');
            return $this->redirectToRoute('homepage');
        }

        $preCadastro = new PreCadastro();
        $form = $this->createForm(PreCadastroType::class, $preCadastro);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $repo->save($preCadastro, true);
            return $this->redirectToRoute('pre_cadastro_index');
        }

        return $this->render('pre_cadastro/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'pre_cadastro_show', methods: ['GET','POST'])]
    public function show(Request $request, PreCadastro $preCadastro, PreCadastroRepository $repo, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$permissionChecker->canAccessModule($usuario, $tenant, 'precadastros')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de pré-cadastros.');
            return $this->redirectToRoute('homepage');
        }

        $form = $this->createForm(PreCadastroType::class, $preCadastro);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $repo->save($preCadastro, true);
            return $this->redirectToRoute('pre_cadastro_index');
        }

        return $this->render('pre_cadastro/show.html.twig', [
            'form' => $form->createView(),
            'preCadastro' => $preCadastro,
        ]);
    }

    #[Route('/{id}/edit', name: 'pre_cadastro_edit', methods: ['GET','POST'])]
    public function edit(Request $request, PreCadastro $preCadastro, PreCadastroRepository $repo, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $usuario */
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$permissionChecker->canAccessModule($usuario, $tenant, 'precadastros')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de pré-cadastros.');
            return $this->redirectToRoute('homepage');
        }

        $form = $this->createForm(PreCadastroType::class, $preCadastro);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $repo->save($preCadastro, true);
            return $this->redirectToRoute('pre_cadastro_index');
        }

        return $this->render('pre_cadastro/edit.html.twig', [
            'form' => $form->createView(),
            'preCadastro' => $preCadastro,
        ]);
    }

    #[Route('/{id}/delete', name: 'pre_cadastro_delete', methods: ['POST'])]
    public function delete(Request $request, PreCadastro $preCadastro, PreCadastroRepository $repo, PermissionChecker $permissionChecker): Response
    {
        /** @var \App\Entity\Auth\User $currentUser */
        $currentUser = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$permissionChecker->canAccessModule($currentUser, $tenant, 'precadastros')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de pré-cadastros.');
            return $this->redirectToRoute('homepage');
        }

        if (!$permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage')) {
            throw $this->createAccessDeniedException('Apenas administradores podem excluir pré-cadastros.');
        }

        if ($this->isCsrfTokenValid('delete'.$preCadastro->getId(), $request->request->get('_token'))) {
            $repo->remove($preCadastro, true);
        }
        return $this->redirectToRoute('pre_cadastro_index');
    }
}