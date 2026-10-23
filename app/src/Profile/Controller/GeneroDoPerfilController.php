<?php

declare(strict_types=1);

namespace App\Profile\Controller;

use App\Entity\Auth\User;
use App\Profile\Enum\Genero;
use App\Profile\Service\JanelaDoGenero;
use App\Profile\UseCase\InformarGeneroUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A janela "Como devemos tratar você?" (spec genero-no-cadastro, regra 3; parcial layout/_globais/genero.html.twig e
 * janela-genero.js). Salvar grava o gênero no próprio perfil; o X fecha só nesta sessão. Os dois só valem para quem
 * está logado e só mexem no próprio usuário: o pedido não leva id de ninguém.
 *
 * O JS manda o formulário com `X-Requested-With` e recebe JSON; sem JS, o formulário volta para a tela inicial.
 */
#[Route('/perfil/genero', name: 'app_profile_genero')]
final class GeneroDoPerfilController extends AbstractController
{
    /** Intenção do CSRF dos dois POSTs da janela. */
    public const CSRF = 'perfil_genero';

    public function __construct(
        private readonly InformarGeneroUseCase $informarGenero,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: '', methods: ['POST'])]
    public function salvar(Request $request): Response
    {
        $user = $this->exigirUsuario();
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->resposta($request, ['erro' => $this->tradutor->trans('moldura.genero.sessao_expirada')], Response::HTTP_FORBIDDEN);
        }

        try {
            $genero = $this->informarGenero->executar($user, (string) $request->request->get('genero'));
        } catch (\InvalidArgumentException) {
            return $this->resposta($request, ['erro' => $this->tradutor->trans(Genero::CHAVE_DO_ERRO)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->resposta($request, ['ok' => true, 'genero' => $genero->value]);
    }

    #[Route('/fechar', name: '_fechar', methods: ['POST'])]
    public function fechar(Request $request): Response
    {
        $user = $this->exigirUsuario();
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->resposta($request, ['erro' => $this->tradutor->trans('moldura.genero.sessao_expirada')], Response::HTTP_FORBIDDEN);
        }
        JanelaDoGenero::fechar($user, $request->getSession());

        return $this->resposta($request, ['ok' => true]);
    }

    private function exigirUsuario(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** @param array<string, mixed> $dados */
    private function resposta(Request $request, array $dados, int $status = Response::HTTP_OK): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse($dados, $status);
        }
        if (isset($dados['erro']) && is_string($dados['erro'])) {
            $this->addFlash('error', $dados['erro']);
        }

        return $this->redirectToRoute('homepage');
    }
}
