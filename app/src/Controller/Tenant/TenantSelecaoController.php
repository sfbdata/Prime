<?php
declare(strict_types=1);

namespace App\Controller\Tenant;

use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Repository\InvitationRepository;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\AvisoDeContaSuspensa;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class TenantSelecaoController extends AbstractController
{
    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
        private readonly TenantContext $tenantContext,
        private readonly InvitationRepository $invitationRepository,
    ) {}

    #[Route('/escritorio/selecionar', name: 'tenant_selecionar', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tenant_selecionar', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Token CSRF inválido.');
            }

            $tenantId = $request->request->getInt('tenant_id');
            if ($tenantId <= 0) {
                $this->addFlash('danger', 'Selecione um escritório válido.');

                return $this->redirectToRoute('tenant_selecionar');
            }

            try {
                $this->tenantContext->setCurrentTenant($tenantId);
            } catch (\Throwable) {
                $this->addFlash('danger', 'Você não tem acesso a este escritório.');

                return $this->redirectToRoute('tenant_selecionar');
            }

            return $this->redirectToRoute('expediente_index');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $vinculos = $this->userTenantRepository->findActiveByUser($user);

        if (count($vinculos) === 1) {
            $this->tenantContext->setCurrentTenant($vinculos[0]->getTenant()->getId());

            return $this->redirectToRoute('expediente_index');
        }

        if (count($vinculos) === 0 && $this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_platform_dashboard');
        }

        // E-mail ainda a confirmar não vê convite (o token sairia para quem talvez não seja dono da caixa).
        $convites = $user->isEmailConfirmado() ? $this->invitationRepository->encontrarPendentesPorEmail($user->getEmail()) : [];

        return $this->render('tenant/selecionar.html.twig', [
            'vinculos' => $vinculos,
            'convites' => $convites,
            'avisosDeConta' => $this->avisosDeContaForaDoAr($request, $user, $vinculos, $convites),
        ]);
    }

    /**
     * C9 e C9b (spec perfis-1.2-contas): sem escritório ativo e sem convite de escritório, mas com vínculo
     * em conta suspensa ou excluída (quarentena), a página mostra o aviso de cada conta no lugar de "Você
     * ainda não faz parte de nenhum escritório". O mesmo texto que a barreira da conta
     * (TenantContextValidatorListener) pôs no flash sai do flash, para não aparecer duas vezes; em
     * qualquer outro destino o flash continua.
     *
     * @param array<UserTenant> $vinculos
     * @param array<Invitation> $convites
     *
     * @return list<array{texto: string, excluida: bool}>
     */
    private function avisosDeContaForaDoAr(Request $request, User $user, array $vinculos, array $convites): array
    {
        $temConviteDeEscritorio = array_filter($convites, static fn (Invitation $c): bool => $c->getTenant() !== null) !== [];
        if ($vinculos !== [] || $temConviteDeEscritorio) {
            return [];
        }

        $avisos = array_map(static fn (array $c): array => [
            'texto'    => $c['excluida'] ? AvisoDeContaSuspensa::paraExcluida($c['nome']) : AvisoDeContaSuspensa::para($c['nome']),
            'excluida' => $c['excluida'],
        ], $this->userTenantRepository->contasForaDoArDoUsuario($user));
        $sessao = $request->getSession();
        if ($avisos !== [] && $sessao instanceof FlashBagAwareSessionInterface) {
            $flashes = $sessao->getFlashBag();
            $flashes->set('warning', array_values(array_diff($flashes->peek('warning'), array_column($avisos, 'texto'))));
        }

        return $avisos;
    }
}
