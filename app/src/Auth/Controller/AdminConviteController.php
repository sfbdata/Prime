<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\ConviteAdminOutput;
use App\Auth\DTO\CriarConvitePlataformaInput;
use App\Auth\DTO\ReenviarConviteInput;
use App\Auth\DTO\RevogarConviteInput;
use App\Auth\Service\ConviteMailer;
use App\Auth\UseCase\CriarConvitePlataformaUseCase;
use App\Auth\UseCase\ReenviarConviteUseCase;
use App\Auth\UseCase\RevogarConviteUseCase;
use App\Repository\InvitationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/platform/convites')]
final class AdminConviteController extends AbstractController
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly CriarConvitePlataformaUseCase $criarUseCase,
        private readonly RevogarConviteUseCase $revogarUseCase,
        private readonly ReenviarConviteUseCase $reenviarUseCase,
        private readonly ConviteMailer $mailer,
        private readonly RateLimiterFactory $conviteCriarLimiter,
    ) {}

    #[Route('', name: 'admin_platform_convite_index', methods: ['GET'])]
    public function index(): Response
    {
        $convites = array_map(
            ConviteAdminOutput::fromInvitation(...),
            $this->invitationRepository->listarDePlataforma()
        );

        return $this->render('admin/platform/convites.html.twig', ['convites' => $convites]);
    }

    #[Route('/novo', name: 'admin_platform_convite_criar', methods: ['POST'])]
    public function criar(Request $request): Response
    {
        $this->verificarLimite($request);

        if (!$this->isCsrfTokenValid('convite_novo', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        try {
            $invitation = $this->criarUseCase->executar(new CriarConvitePlataformaInput(
                email: (string) $request->request->get('email', ''),
                fullName: (string) $request->request->get('full_name', '') ?: null,
                criadoPor: $this->getUser(),
            ));

            try {
                $this->mailer->enviarConvitePlataforma($invitation);
            } catch (\RuntimeException) {
                $this->addFlash('erro', 'Convite criado, mas o email não foi enviado. Use o botão Reenviar.');

                return $this->redirectToRoute('admin_platform_convite_index');
            }

            $this->addFlash('sucesso', sprintf('Convite enviado para %s.', $invitation->getEmail()));
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->redirectToRoute('admin_platform_convite_index');
    }

    #[Route('/{token}/revogar', name: 'admin_platform_convite_revogar', methods: ['POST'])]
    public function revogar(string $token, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('convite_revogar_' . $token, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $invitation = $this->invitationRepository->encontrarPorToken($token);

        if ($invitation === null || $invitation->getType() !== 'platform') {
            throw $this->createNotFoundException('Convite de plataforma não encontrado.');
        }

        try {
            $this->revogarUseCase->executar(new RevogarConviteInput($token));
            $this->addFlash('sucesso', 'Convite revogado.');
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->redirectToRoute('admin_platform_convite_index');
    }

    #[Route('/{token}/reenviar', name: 'admin_platform_convite_reenviar', methods: ['POST'])]
    public function reenviar(string $token, Request $request): Response
    {
        $this->verificarLimite($request);

        if (!$this->isCsrfTokenValid('convite_reenviar_' . $token, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $invitation = $this->invitationRepository->encontrarPorToken($token);

        if ($invitation === null || $invitation->getType() !== 'platform') {
            throw $this->createNotFoundException('Convite de plataforma não encontrado.');
        }

        try {
            $invitation = $this->reenviarUseCase->executar(new ReenviarConviteInput($token));

            try {
                $this->mailer->enviarConvitePlataforma($invitation);
            } catch (\RuntimeException) {
                $this->addFlash('erro', 'Não foi possível enviar o email. Tente novamente.');

                return $this->redirectToRoute('admin_platform_convite_index');
            }

            $this->addFlash('sucesso', 'Convite reenviado.');
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->redirectToRoute('admin_platform_convite_index');
    }

    private function verificarLimite(Request $request): void
    {
        if (!$this->conviteCriarLimiter->create((string) $this->getUser()->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Muitas tentativas. Tente novamente em alguns minutos.');
        }
    }
}
