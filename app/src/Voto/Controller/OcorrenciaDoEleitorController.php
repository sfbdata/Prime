<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\Exception\CedulaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\UseCase\MontarFormularioDaOcorrenciaUseCase;
use App\Voto\UseCase\RegistrarOcorrenciaDaVotacaoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Registrar ocorrência" do eleitor, no Portal do Eleitor (`/votar/{token}/ocorrencia`). Mesma porta da cédula: sem login,
 * pelo token da unidade, com o `TenantFilter` desligado (`CedulaSemTenantFilterListener`). Token que não leva a nenhuma
 * unidade (o eleitor só alcança a assembleia da própria unidade) responde 404 e consome o limitador da cédula.
 * O envio é POST com o CSRF da cédula e volta ao formulário (PRG) com o aviso.
 */
#[Route('/votar/{token}/ocorrencia', requirements: ['token' => '[a-f0-9]{40}'])]
final class OcorrenciaDoEleitorController extends AbstractController
{
    public function __construct(
        private readonly MontarFormularioDaOcorrenciaUseCase $montar,
        private readonly RegistrarOcorrenciaDaVotacaoUseCase $registrar,
        private readonly RateLimiterFactoryInterface $votoCedulaInvalidoLimiter,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'voto_assembleia_cedula_ocorrencia', methods: ['GET'])]
    public function formulario(Request $request, string $token): Response
    {
        $formulario = $this->montar->executar($token);
        if (null === $formulario) {
            return $this->naoEncontrada($request);
        }

        return $this->protegida($this->render('voto/cedula/ocorrencia.html.twig', ['formulario' => $formulario, 'token' => $token]));
    }

    #[Route('', name: 'voto_assembleia_cedula_ocorrencia_enviar', methods: ['POST'])]
    public function enviar(Request $request, string $token): Response
    {
        if (!$this->isCsrfTokenValid(CedulaController::CSRF_PREFIXO . $token, $request->request->getString('_token'))) {
            $this->addFlash(CedulaController::FLASH_ERRO, $this->tradutor->trans('voto.assembleia.cedula.toast.expirada'));

            return $this->volta($token, 'voto_assembleia_cedula_ocorrencia');
        }
        $pauta = $request->request->getString('pauta');

        try {
            $this->registrar->executar($token, 1 === preg_match('/^\d{1,9}$/', $pauta) ? (int) $pauta : null, $request->request->getString('texto'));
            $this->addFlash(CedulaController::FLASH_OK, $this->tradutor->trans('voto.assembleia.cedula.ocorrencia.registrada'));

            return $this->volta($token, 'voto_assembleia_cedula');
        } catch (CedulaNaoEncontradaException) {
            return $this->naoEncontrada($request);
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            $this->addFlash(CedulaController::FLASH_ERRO, $recusa->getMessage());

            return $this->volta($token, 'voto_assembleia_cedula_ocorrencia');
        }
    }

    private function volta(string $token, string $rota): Response
    {
        return $this->protegida($this->redirectToRoute($rota, ['token' => $token], 303));
    }

    private function naoEncontrada(Request $request): Response
    {
        $aceito = $this->votoCedulaInvalidoLimiter->create($request->getClientIp())->consume()->isAccepted();

        return $this->protegida($this->render('voto/cedula/indisponivel.html.twig', [
            'limite' => !$aceito,
        ], new Response('', $aceito ? Response::HTTP_NOT_FOUND : Response::HTTP_TOO_MANY_REQUESTS)));
    }

    private function protegida(Response $resposta): Response
    {
        $resposta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $resposta->headers->set('Cache-Control', 'no-store, private');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }
}
