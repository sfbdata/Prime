<?php

declare(strict_types=1);

namespace App\Termo\Controller;

use App\Entity\Auth\User;
use App\EventListener\TermoAceiteListener;
use App\Service\Tenant\TenantContext;
use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\TermoVigente;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class TermoController extends AbstractController
{
    public function __construct(
        private readonly RegistrarAceiteTermoUseCase $registrarAceite,
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/termos/aceitar', name: 'termo_aceite', methods: ['GET'])]
    public function aceitar(): Response
    {
        return $this->render('termo/aceite.html.twig', [
            'versao' => TermoVigente::VERSAO,
        ]);
    }

    #[Route('/termos/aceitar', name: 'termo_aceite_registrar', methods: ['POST'])]
    public function registrar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('termo_aceite', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        if (!$request->request->getBoolean('aceito')) {
            $this->addFlash('erro', 'É necessário marcar a confirmação de leitura e aceite dos Termos de Uso.');

            return $this->redirectToRoute('termo_aceite');
        }

        /** @var User $user */
        $user = $this->getUser();

        $this->registrarAceite->executar(new RegistrarAceiteTermoInput(
            user: $user,
            ip: (string) $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
            tenant: $this->tenantContext->getCurrentTenant(),
        ));

        $request->getSession()->set(TermoAceiteListener::SESSION_KEY, TermoVigente::VERSAO);

        return $this->redirectToRoute('homepage');
    }
}
