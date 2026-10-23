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
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Repository\InvitationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly TranslatorInterface $tradutor,
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
                $this->addFlash('erro', $this->tradutor->trans('plataforma.flash.convite_criado_sem_email'));

                return $this->redirectToRoute('admin_platform_convite_index');
            }

            $this->addFlash('sucesso', $this->tradutor->trans('plataforma.flash.convite_enviado', ['email' => $invitation->getEmail()]));
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->redirectToRoute('admin_platform_convite_index');
    }

    #[Route('/{id}/revogar', name: 'admin_platform_convite_revogar', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function revogar(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('convite_revogar_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Pelo id: o token só existe no link do e-mail (achado de auditoria interna F7). O de escritório é 404.
        if ($this->invitationRepository->findOneBy(['id' => $id, 'type' => Invitation::TIPO_PLATAFORMA]) === null) {
            throw $this->createNotFoundException('Convite de plataforma não encontrado.');
        }

        try {
            $this->revogarUseCase->executar(new RevogarConviteInput($id));
            $this->addFlash('sucesso', $this->tradutor->trans('plataforma.flash.convite_revogado'));
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->redirectToRoute('admin_platform_convite_index');
    }

    #[Route('/{id}/reenviar', name: 'admin_platform_convite_reenviar', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function reenviar(int $id, Request $request): Response
    {
        $this->verificarLimite($request);

        if (!$this->isCsrfTokenValid('convite_reenviar_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Pelo id: o token só existe no link do e-mail (achado de auditoria interna F7). O de escritório é 404.
        if ($this->invitationRepository->findOneBy(['id' => $id, 'type' => Invitation::TIPO_PLATAFORMA]) === null) {
            throw $this->createNotFoundException('Convite de plataforma não encontrado.');
        }

        $quem = $this->getUser();
        if (!$quem instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $invitation = $this->reenviarUseCase->executar(new ReenviarConviteInput($id, $quem));

            try {
                $this->mailer->enviarConvitePlataforma($invitation);
            } catch (\RuntimeException) {
                $this->addFlash('erro', $this->tradutor->trans('plataforma.flash.convite_email_nao_enviado'));

                return $this->redirectToRoute('admin_platform_convite_index');
            }

            $this->addFlash('sucesso', $this->tradutor->trans('plataforma.flash.convite_reenviado'));
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
