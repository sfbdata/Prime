<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Ponto\Feriado;
use App\Entity\Tenant\Tenant;
use App\Form\FeriadoType;
use App\Repository\Ponto\FeriadoRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/tenant/{tenantId}/feriados')]
final class FeriadoController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    private function assertAccess(int $tenantId, PermissionChecker $checker): Tenant
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $tenant->getId() !== $tenantId) {
            throw $this->createAccessDeniedException('Você não tem acesso aos feriados deste escritório.');
        }

        if (!$checker->canAdminister($user, $tenant, 'admin.tenant.settings.manage')) {
            throw $this->createAccessDeniedException('Você não tem permissão para gerenciar feriados.');
        }

        return $tenant;
    }

    #[Route('', name: 'app_feriado_index', methods: ['GET'])]
    public function index(
        int $tenantId,
        FeriadoRepository $feriadoRepository,
        PermissionChecker $checker
    ): Response {
        $tenant = $this->assertAccess($tenantId, $checker);

        $feriados = $feriadoRepository->findByTenantOrdenado($tenant);

        return $this->render('feriado/index.html.twig', [
            'tenantId' => $tenantId,
            'feriados' => $feriados,
        ]);
    }

    #[Route('/novo', name: 'app_feriado_new', methods: ['GET', 'POST'])]
    public function new(
        int $tenantId,
        Request $request,
        EntityManagerInterface $em,
        PermissionChecker $checker
    ): Response {
        $tenant = $this->assertAccess($tenantId, $checker);

        $feriado = new Feriado();
        $feriado->setTenant($tenant);
        $feriado->setRecorrente(true);

        $form = $this->createForm(FeriadoType::class, $feriado);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($feriado);
            $em->flush();

            $this->addFlash('success', 'Feriado cadastrado com sucesso.');

            return $this->redirectToRoute('app_feriado_index', ['tenantId' => $tenantId]);
        }

        return $this->render('feriado/new.html.twig', [
            'tenantId' => $tenantId,
            'form'     => $form,
        ]);
    }

    #[Route('/{id}/editar', name: 'app_feriado_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $tenantId,
        Feriado $feriado,
        Request $request,
        EntityManagerInterface $em,
        PermissionChecker $checker
    ): Response {
        $tenant = $this->assertAccess($tenantId, $checker);

        if ($feriado->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Feriado não encontrado.');
        }

        $form = $this->createForm(FeriadoType::class, $feriado);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'Feriado atualizado com sucesso.');

            return $this->redirectToRoute('app_feriado_index', ['tenantId' => $tenantId]);
        }

        return $this->render('feriado/edit.html.twig', [
            'tenantId' => $tenantId,
            'feriado'  => $feriado,
            'form'     => $form,
        ]);
    }

    #[Route('/{id}/excluir', name: 'app_feriado_delete', methods: ['POST'])]
    public function delete(
        int $tenantId,
        Feriado $feriado,
        Request $request,
        EntityManagerInterface $em,
        PermissionChecker $checker
    ): Response {
        $tenant = $this->assertAccess($tenantId, $checker);

        if ($feriado->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Feriado não encontrado.');
        }

        if ($this->isCsrfTokenValid('excluir_feriado_' . $feriado->getId(), $request->request->get('_token'))) {
            $em->remove($feriado);
            $em->flush();

            $this->addFlash('success', 'Feriado excluído.');
        }

        return $this->redirectToRoute('app_feriado_index', ['tenantId' => $tenantId]);
    }

}
