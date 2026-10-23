<?php

declare(strict_types=1);

namespace App\Termo\Controller;

use App\Entity\Auth\User;
use App\EventListener\TermoAceiteListener;
use App\Service\Tenant\TenantContext;
use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\Enum\OrigemDoAceite;
use App\Termo\TermoVigente;
use App\Termo\UseCase\RatificarCienciaPoliticaUseCase;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Aceite dos Termos de Uso. Só em português (decisão do dono, 23/10/2026): o aceite fica amarrado ao texto pt da
 * versão ({@see TermoVigente}), então as rotas fixam o locale `pt_BR`, qualquer que seja o idioma da pessoa, e o
 * {@see \App\Profile\EventListener\IdiomaDaRequisicaoListener} respeita.
 */
#[IsGranted('ROLE_USER')]
final class TermoController extends AbstractController
{
    public function __construct(
        private readonly RegistrarAceiteTermoUseCase $registrarAceite,
        private readonly TenantContext $tenantContext,
        private readonly RatificarCienciaPoliticaUseCase $ratificarCiencia,
    ) {}

    #[Route('/termos/aceitar', name: 'termo_aceite', methods: ['GET'], locale: 'pt_BR')]
    public function aceitar(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('termo/aceite.html.twig', [
            'versao' => TermoVigente::VERSAO,
            'pedirPolitica' => $this->ratificarCiencia->precisa($user),
        ]);
    }

    #[Route('/termos/aceitar', name: 'termo_aceite_registrar', methods: ['POST'], locale: 'pt_BR')]
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

        // D2b: quem confirmou a conta pelo código declara também a ciência da Política (a caixa diz isso). Ela vem
        // ANTES do aceite: se o aceite falhar, o gate (que olha os Termos) para a pessoa de novo e nada fica pela metade.
        $this->ratificarCiencia->executar($user, (string) $request->getClientIp(), $request->headers->get('User-Agent'));
        $this->registrarAceite->executar(new RegistrarAceiteTermoInput(
            user: $user,
            ip: (string) $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
            tenant: $this->tenantContext->getCurrentTenant(),
            origem: OrigemDoAceite::Reaceite,
        ));

        $request->getSession()->set(TermoAceiteListener::SESSION_KEY, TermoVigente::VERSAO);

        return $this->redirectToRoute('homepage');
    }
}
