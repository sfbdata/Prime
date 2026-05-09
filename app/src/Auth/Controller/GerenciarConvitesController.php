<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\ConviteAdminOutput;
use App\Auth\DTO\CriarConviteEscritorioInput;
use App\Auth\DTO\ReenviarConviteInput;
use App\Auth\DTO\RevogarConviteInput;
use App\Auth\Service\ConviteMailer;
use App\Auth\UseCase\CriarConviteEscritorioUseCase;
use App\Auth\UseCase\ReenviarConviteUseCase;
use App\Auth\UseCase\RevogarConviteUseCase;
use App\Repository\InvitationRepository;
use App\Repository\TenantRoleRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/escritorio/convites')]
final class GerenciarConvitesController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $checker,
        private readonly InvitationRepository $invitationRepository,
        private readonly TenantRoleRepository $tenantRoleRepository,
        private readonly CriarConviteEscritorioUseCase $criarUseCase,
        private readonly RevogarConviteUseCase $revogarUseCase,
        private readonly ReenviarConviteUseCase $reenviarUseCase,
        private readonly ConviteMailer $mailer,
        private readonly RateLimiterFactory $conviteCriarLimiter,
    ) {}

    #[Route('', name: 'auth_gerenciar_convites', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAccess();

        $tenant = $this->tenantContext->getCurrentTenant();
        $roles = $this->tenantRoleRepository->findByTenantId($tenant->getId());
        $convites = array_map(
            ConviteAdminOutput::fromInvitation(...),
            $this->invitationRepository->listarPorTenant($tenant)
        );

        return $this->render('auth/convites/gerenciar.html.twig', [
            'convites' => $convites,
            'roles' => $roles,
        ]);
    }

    #[Route('/novo', name: 'auth_gerenciar_convites_criar', methods: ['POST'])]
    public function criar(Request $request): Response
    {
        $this->assertAccess();
        $this->verificarLimite($request);

        if (!$this->isCsrfTokenValid('convite_novo', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $tenantRoleId = (int) $request->request->get('tenant_role_id', 0);
        $role = $this->tenantRoleRepository->find($tenantRoleId);

        if ($role === null || $role->getTenant()?->getId() !== $tenant->getId()) {
            $this->addFlash('erro', 'Perfil inválido ou não pertence a este escritório.');

            return $this->redirectToRoute('auth_gerenciar_convites');
        }

        try {
            $invitation = $this->criarUseCase->executar(new CriarConviteEscritorioInput(
                email: (string) $request->request->get('email', ''),
                fullName: (string) $request->request->get('full_name', '') ?: null,
                tenant: $tenant,
                tenantRole: $role,
                criadoPor: $this->getUser(),
            ));

            try {
                $this->mailer->enviarConviteEscritorio($invitation);
            } catch (\RuntimeException) {
                $this->addFlash('erro', 'Convite criado, mas o email não foi enviado. Use o botão Reenviar.');

                return $this->redirectToRoute('auth_gerenciar_convites');
            }

            $this->addFlash('sucesso', sprintf('Convite enviado para %s.', $invitation->getEmail()));
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->redirectToRoute('auth_gerenciar_convites');
    }

    #[Route('/{token}/revogar', name: 'auth_gerenciar_convites_revogar', methods: ['POST'])]
    public function revogar(string $token, Request $request): Response
    {
        $this->assertAccess();

        if (!$this->isCsrfTokenValid('convite_revogar_' . $token, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $invitation = $this->invitationRepository->encontrarPorToken($token);

        if ($invitation === null || $invitation->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Convite não encontrado neste escritório.');
        }

        try {
            $this->revogarUseCase->executar(new RevogarConviteInput($token));
            $this->addFlash('sucesso', 'Convite revogado.');
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->redirectToRoute('auth_gerenciar_convites');
    }

    #[Route('/{token}/reenviar', name: 'auth_gerenciar_convites_reenviar', methods: ['POST'])]
    public function reenviar(string $token, Request $request): Response
    {
        $this->assertAccess();
        $this->verificarLimite($request);

        if (!$this->isCsrfTokenValid('convite_reenviar_' . $token, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $invitation = $this->invitationRepository->encontrarPorToken($token);

        if ($invitation === null || $invitation->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Convite não encontrado neste escritório.');
        }

        try {
            $invitation = $this->reenviarUseCase->executar(new ReenviarConviteInput($token));

            try {
                $this->mailer->enviarConviteEscritorio($invitation);
            } catch (\RuntimeException) {
                $this->addFlash('erro', 'Não foi possível enviar o email. Tente novamente.');

                return $this->redirectToRoute('auth_gerenciar_convites');
            }

            $this->addFlash('sucesso', 'Convite reenviado.');
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->redirectToRoute('auth_gerenciar_convites');
    }

    private function assertAccess(): void
    {
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$this->checker->canAdminister($user, $tenant, 'admin.users.invite')) {
            throw $this->createAccessDeniedException('Você não tem permissão para gerenciar convites.');
        }
    }

    private function verificarLimite(Request $request): void
    {
        if (!$this->conviteCriarLimiter->create((string) $this->getUser()->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Muitas tentativas. Tente novamente em alguns minutos.');
        }
    }
}
