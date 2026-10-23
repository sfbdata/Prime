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
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Repository\InvitationRepository;
use App\Repository\TenantRoleRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\HierarquiaDePerfis;
use App\Tenant\Service\RegraDosPrimarios;
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
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly HierarquiaDePerfis $hierarquia,
    ) {}

    #[Route('', name: 'auth_gerenciar_convites', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAccess();

        $tenant = $this->tenantContext->getCurrentTenant();
        $roles = $this->tenantRoleRepository->findByTenantId($tenant->getId());
        // Convidar já como primário é dar o perfil de sistema: só primário ou Master (spec D4).
        // O UseCase revalida; aqui só tira a opção de quem não pode.
        if (!$this->regraDosPrimarios->podeGerirPrimarios($this->getUser(), $tenant)) {
            $roles = array_values(array_filter($roles, static fn ($r): bool => !$r->isSystem()));
        }
        // C5: quem não é primário nem Master só vê os perfis que cabem nas permissões dele (o UseCase revalida).
        $ator  = $this->getUser();
        $roles = array_values(array_filter($roles, fn ($r): bool => $ator instanceof User && $this->hierarquia->podeAtribuirPerfil($ator, $tenant, $r)));
        // C5 também na lista: só o convite de perfil que quem gerencia poderia atribuir (achado de auditoria interna F5).
        // O alcance lê o vínculo de quem gerencia uma vez só, não um por convite.
        $alcance  = $ator instanceof User ? $this->hierarquia->alcanceDoAtor($ator, $tenant) : static fn (): bool => false;
        $convites = array_map(
            ConviteAdminOutput::fromInvitation(...),
            array_values(array_filter(
                $this->invitationRepository->listarPorTenant($tenant),
                static fn (Invitation $i): bool => $alcance($i->getTenantRole()),
            ))
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

            return $this->voltarDoConvite($request);
        }

        try {
            $emitido = $this->criarUseCase->executar(new CriarConviteEscritorioInput(
                email: (string) $request->request->get('email', ''),
                fullName: (string) $request->request->get('full_name', '') ?: null,
                tenant: $tenant,
                tenantRole: $role,
                criadoPor: $this->getUser(),
            ));

            try {
                $this->mailer->enviarConviteEscritorio($emitido->convite, $emitido->token);
            } catch (\RuntimeException) {
                $this->addFlash('erro', 'Convite criado, mas o email não foi enviado. Use o botão Reenviar.');

                return $this->voltarDoConvite($request);
            }

            $this->addFlash('sucesso', sprintf('Convite enviado para %s.', $emitido->convite->getEmail()));
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->voltarDoConvite($request);
    }

    #[Route('/{id}/revogar', name: 'auth_gerenciar_convites_revogar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revogar(int $id, Request $request): Response
    {
        $this->assertAccess();

        if (!$this->isCsrfTokenValid('convite_revogar_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $this->exigirConviteNoAlcance($id);

        try {
            $this->revogarUseCase->executar(new RevogarConviteInput($id));
            $this->addFlash('sucesso', 'Convite revogado.');
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->redirectToRoute('auth_gerenciar_convites');
    }

    #[Route('/{id}/reenviar', name: 'auth_gerenciar_convites_reenviar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reenviar(int $id, Request $request): Response
    {
        $this->assertAccess();
        $this->verificarLimite($request);

        if (!$this->isCsrfTokenValid('convite_reenviar_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $this->exigirConviteNoAlcance($id);

        $quem = $this->getUser();
        if (!$quem instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $emitido = $this->reenviarUseCase->executar(new ReenviarConviteInput($id, $quem));

            try {
                $this->mailer->enviarConviteEscritorio($emitido->convite, $emitido->token);
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

    /**
     * Posse por escritório e C5 por perfil (achados de auditoria interna F4 e F5): o convite de outro escritório, ou de
     * perfil que quem gerencia não poderia atribuir, responde 404, como o inexistente.
     */
    private function exigirConviteNoAlcance(int $id): void
    {
        $tenant     = $this->tenantContext->getCurrentTenant();
        $invitation = $this->invitationRepository->findOneBy(['id' => $id, 'tenant' => $tenant]);

        if ($invitation === null || !$this->estaNoAlcance($invitation)) {
            throw $this->createNotFoundException('Convite não encontrado neste escritório.');
        }
    }

    /** Primário e Master alcançam todos; os demais, só o convite sem perfil ou de perfil contido no deles. */
    private function estaNoAlcance(Invitation $invitation): bool
    {
        $ator = $this->getUser();

        return $ator instanceof User
            && $this->hierarquia->podeMexerNoAlvo($ator, $this->tenantContext->getCurrentTenant(), $invitation->getTenantRole());
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

    /**
     * Convite enviado pela aba "Minha conta" do modal (campo `_voltar`) volta para a página em que a pessoa estava
     * (spec equipe-da-conta §4); só aceita endereço deste mesmo site. Pela página de convites, volta para ela.
     */
    private function voltarDoConvite(Request $request): Response
    {
        $anterior = (string) $request->headers->get('referer');
        if ($request->request->get('_voltar') === '1' && $anterior !== '' && parse_url($anterior, PHP_URL_HOST) === $request->getHost()) {
            return $this->redirect($anterior);
        }

        return $this->redirectToRoute('auth_gerenciar_convites');
    }
}
