<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\DTO\RegistrarVotoInput;
use App\Voto\Exception\CedulaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\UseCase\MontarCedulaUseCase;
use App\Voto\UseCase\RegistrarVotoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cédula da unidade na assembleia de condomínio, pelo link pessoal `/votar/{token}` (spec
 * docs/specs/voto-assembleia-condominio.md, seções 1, 4 e 5). SEM login (`access_control` PUBLIC_ACCESS), fora das
 * guardas de termo e de escritório, com o `TenantFilter` desligado (`CedulaSemTenantFilterListener`): toda consulta usa
 * o escritório da assembleia.
 *
 * Token inexistente, ou escritório sem o Voto liberado, responde 404 e consome o limitador `voto_cedula_invalido`
 * (20 por minuto por IP; estourou, 429). Token válido não passa pelo limitador: na assembleia presencial muitos
 * moradores usam o mesmo wi-fi. Toda resposta sai com `noindex`, sem cache e sem Referer.
 *
 * O voto é POST com CSRF `voto_cedula_{token}` e volta à cédula (PRG) com a mensagem no aviso da página.
 */
#[Route('/votar/{token}', requirements: ['token' => '[a-f0-9]{40}'])]
final class CedulaController extends AbstractController
{
    public const CSRF_PREFIXO = 'voto_cedula_';
    public const FLASH_OK = 'voto_cedula_ok';
    public const FLASH_ERRO = 'voto_cedula_erro';

    public function __construct(
        private readonly MontarCedulaUseCase $montar,
        private readonly RegistrarVotoUseCase $registrar,
        private readonly RateLimiterFactoryInterface $votoCedulaInvalidoLimiter,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'voto_assembleia_cedula', methods: ['GET'])]
    public function cedula(Request $request, string $token): Response
    {
        $cedula = $this->montar->executar($token);
        if (null === $cedula) {
            return $this->naoEncontrada($request);
        }

        return $this->protegida($this->render('voto/cedula/cedula.html.twig', [
            'cedula' => $cedula,
            'token' => $token,
        ]));
    }

    #[Route('/pauta/{pauta}', name: 'voto_assembleia_cedula_votar', requirements: ['pauta' => '\d+'], methods: ['POST'])]
    public function votar(Request $request, string $token, int $pauta): Response
    {
        $json = str_contains((string) $request->headers->get('Accept'), 'application/json');

        if (!$this->isCsrfTokenValid(self::CSRF_PREFIXO . $token, (string) $request->request->get('_token'))) {
            if ($json) {
                return $this->respostaJson(false, $this->tradutor->trans('voto.assembleia.cedula.toast.expirada'), Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash(self::FLASH_ERRO, $this->tradutor->trans('voto.assembleia.cedula.toast.expirada'));

            return $this->deVoltaACedula($token);
        }

        try {
            $this->registrar->executar(new RegistrarVotoInput($token, $pauta, $request->request->getInt('opcao')));
            if ($json) {
                return $this->respostaJson(true, $this->tradutor->trans('voto.assembleia.cedula.toast.registrado'));
            }
            $this->addFlash(self::FLASH_OK, $this->tradutor->trans('voto.assembleia.cedula.toast.registrado'));
        } catch (CedulaNaoEncontradaException) {
            return $this->naoEncontrada($request);
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            if ($json) {
                return $this->respostaJson(false, $recusa->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash(self::FLASH_ERRO, $recusa->getMessage());
        }

        return $this->deVoltaACedula($token);
    }

    /** Resposta da cabine (js/voto-cabine.js): o voto vai por fetch e a tela decide o que mostrar. */
    private function respostaJson(bool $ok, string $mensagem, int $status = Response::HTTP_OK): Response
    {
        return $this->protegida(new JsonResponse(['ok' => $ok, 'mensagem' => $mensagem], $status));
    }

    /** 404 que consome o limitador por IP; estourou, 429. Mesma página nos dois casos. */
    private function naoEncontrada(Request $request): Response
    {
        $aceito = $this->votoCedulaInvalidoLimiter->create($request->getClientIp())->consume()->isAccepted();

        return $this->protegida($this->render('voto/cedula/indisponivel.html.twig', [
            'limite' => !$aceito,
        ], new Response('', $aceito ? Response::HTTP_NOT_FOUND : Response::HTTP_TOO_MANY_REQUESTS)));
    }

    private function deVoltaACedula(string $token): Response
    {
        return $this->protegida($this->redirectToRoute('voto_assembleia_cedula', ['token' => $token]));
    }

    private function protegida(Response $resposta): Response
    {
        $resposta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $resposta->headers->set('Cache-Control', 'no-store, private');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }
}
