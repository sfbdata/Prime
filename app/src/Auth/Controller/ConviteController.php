<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\AceitarConviteEscritorioComContaInput;
use App\Auth\DTO\AceitarConviteEscritorioSemContaInput;
use App\Auth\DTO\AceitarConvitePlataformaInput;
use App\Auth\DTO\ConviteOutput;
use App\Auth\DTO\RecusarConviteEscritorioInput;
use App\Auth\UseCase\AceitarConviteEscritorioComContaUseCase;
use App\Auth\UseCase\AceitarConviteEscritorioSemContaUseCase;
use App\Auth\UseCase\AceitarConvitePlataformaUseCase;
use App\Auth\UseCase\RecusarConviteEscritorioUseCase;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ConviteController extends AbstractController
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly AceitarConvitePlataformaUseCase $aceitarPlataformaUseCase,
        private readonly AceitarConviteEscritorioSemContaUseCase $aceitarSemContaUseCase,
        private readonly AceitarConviteEscritorioComContaUseCase $aceitarComContaUseCase,
        private readonly RecusarConviteEscritorioUseCase $recusarUseCase,
        private readonly RateLimiterFactory $conviteAceiteLimiter,
    ) {}

    #[Route('/convite/{token}', name: 'auth_aceite_convite', methods: ['GET'])]
    public function verConvite(string $token, Request $request): Response
    {
        $this->verificarLimite($request);

        $invitation = $this->invitationRepository->encontrarPorToken($token);

        if ($invitation === null) {
            return $this->render('auth/convite/erro.html.twig', ['motivo' => 'nao_encontrado', 'convite' => null]);
        }

        if ($invitation->isExpired() || $invitation->getStatus() === 'expired') {
            return $this->render('auth/convite/erro.html.twig', [
                'motivo' => 'expirado',
                'convite' => ConviteOutput::fromInvitation($invitation),
            ]);
        }

        if ($invitation->getStatus() !== 'pending') {
            return $this->render('auth/convite/erro.html.twig', [
                'motivo' => 'ja_utilizado',
                'convite' => ConviteOutput::fromInvitation($invitation),
            ]);
        }

        $output = ConviteOutput::fromInvitation($invitation);

        if ($invitation->getType() === 'plataforma') {
            return $this->render('auth/convite/ver.html.twig', ['convite' => $output, 'tipo_form' => 'plataforma']);
        }

        $userExistente = $this->userRepository->findOneBy(['email' => $invitation->getEmail()]);

        if ($userExistente === null) {
            return $this->render('auth/convite/ver.html.twig', ['convite' => $output, 'tipo_form' => 'escritorio']);
        }

        $usuarioLogado = $this->getUser();

        if ($usuarioLogado === null) {
            $request->getSession()->set(
                '_security.main.target_path',
                $this->generateUrl('auth_aceite_convite', ['token' => $token])
            );

            return $this->redirectToRoute('app_login');
        }

        if (strtolower($usuarioLogado->getUserIdentifier()) !== strtolower($invitation->getEmail())) {
            return $this->render('auth/convite/nao_pertence.html.twig', ['convite' => $output]);
        }

        return $this->render('auth/convite/ver_logado.html.twig', ['convite' => $output]);
    }

    #[Route('/convite/{token}/plataforma', name: 'auth_aceite_convite_plataforma', methods: ['POST'])]
    public function aceitarPlataforma(string $token, Request $request): Response
    {
        $this->verificarLimite($request);
        $this->verificarCsrf($token, $request);

        try {
            $this->aceitarPlataformaUseCase->executar(new AceitarConvitePlataformaInput(
                token: $token,
                fullName: (string) $request->request->get('full_name', ''),
                senha: (string) $request->request->get('senha', ''),
                oabNumero: (string) $request->request->get('oab_numero', ''),
                oabUf: strtoupper((string) $request->request->get('oab_uf', '')),
            ));
            $this->addFlash('sucesso', 'Conta criada com sucesso! Faça login para continuar.');

            return $this->redirectToRoute('app_login');
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addFlash('erro', $e->getMessage());

            return $this->redirectToRoute('auth_aceite_convite', ['token' => $token]);
        }
    }

    #[Route('/convite/{token}/criar-conta', name: 'auth_aceite_convite_criar_conta', methods: ['POST'])]
    public function aceitarSemConta(string $token, Request $request): Response
    {
        $this->verificarLimite($request);
        $this->verificarCsrf($token, $request);

        try {
            $this->aceitarSemContaUseCase->executar(new AceitarConviteEscritorioSemContaInput(
                token: $token,
                fullName: (string) $request->request->get('full_name', ''),
                senha: (string) $request->request->get('senha', ''),
            ));
            $this->addFlash('sucesso', 'Conta criada com sucesso! Faça login para continuar.');

            return $this->redirectToRoute('app_login');
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addFlash('erro', $e->getMessage());

            return $this->redirectToRoute('auth_aceite_convite', ['token' => $token]);
        }
    }

    #[Route('/convite/{token}/aceitar', name: 'auth_aceite_convite_aceitar', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function aceitarLogado(string $token, Request $request): Response
    {
        $this->verificarLimite($request);
        $this->verificarCsrf($token, $request);

        try {
            $userTenant = $this->aceitarComContaUseCase->executar(new AceitarConviteEscritorioComContaInput(
                token: $token,
                usuarioAtual: $this->getUser(),
            ));
            $tenantNome = $userTenant->getTenant()->getName() ?? 'escritório';
            $this->addFlash('sucesso', sprintf('Bem-vindo ao escritório %s!', $tenantNome));
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());

            return $this->redirectToRoute('auth_aceite_convite', ['token' => $token]);
        }

        return $this->redirectToRoute('homepage');
    }

    #[Route('/convite/{token}/recusar', name: 'auth_aceite_convite_recusar', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function recusar(string $token, Request $request): Response
    {
        $this->verificarLimite($request);
        $this->verificarCsrf($token, $request);

        try {
            $this->recusarUseCase->executar(new RecusarConviteEscritorioInput(
                token: $token,
                usuarioAtual: $this->getUser(),
            ));
            $this->addFlash('info', 'Convite recusado.');
        } catch (\DomainException $e) {
            $this->addFlash('erro', $e->getMessage());
        }

        return $this->redirectToRoute('homepage');
    }

    #[Route('/convites', name: 'auth_meus_convites', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function meusConvites(): Response
    {
        $pendentes = $this->invitationRepository->encontrarPendentesPorEmail(
            (string) $this->getUser()->getUserIdentifier()
        );

        $convites = array_values(array_map(
            ConviteOutput::fromInvitation(...),
            array_filter($pendentes, static fn($i) => $i->getType() === 'escritorio')
        ));

        return $this->render('auth/meus_convites.html.twig', ['convites' => $convites]);
    }

    private function verificarLimite(Request $request): void
    {
        if (!$this->conviteAceiteLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Muitas tentativas. Tente novamente em alguns minutos.');
        }
    }

    private function verificarCsrf(string $token, Request $request): void
    {
        if (!$this->isCsrfTokenValid('convite_' . $token, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }
}
