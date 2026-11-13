<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\DTO\EnviarComprovantePorEmailInput;
use App\Voto\Exception\CedulaNaoEncontradaException;
use App\Voto\Exception\LimiteDeEnviosDoComprovanteException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\UseCase\EnviarComprovantePorEmailUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ComprovanteDaCedulaController extends AbstractController
{
    public function __construct(
        private readonly EnviarComprovantePorEmailUseCase $enviar,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/votar/{token}/pauta/{pauta}/comprovante/email', name: 'voto_assembleia_cedula_comprovante_email', requirements: ['token' => '[a-f0-9]{40}', 'pauta' => '\d+'], methods: ['POST'])]
    public function enviar(Request $request, string $token, int $pauta): Response
    {
        if (!$this->isCsrfTokenValid(CedulaController::CSRF_PREFIXO . $token, $request->request->getString('_token'))) {
            return $this->responder($request, $token, 'A página expirou. Recarregue e tente novamente.', 400);
        }
        try {
            $this->enviar->executar(new EnviarComprovantePorEmailInput($token, $pauta, trim($request->request->getString('email'))));
        } catch (CedulaNaoEncontradaException) {
            throw $this->createNotFoundException();
        } catch (LimiteDeEnviosDoComprovanteException $recusa) {
            return $this->responder($request, $token, $recusa->getMessage(), 429);
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            return $this->responder($request, $token, $recusa->getMessage(), 422);
        } catch (\Exception) {
            $this->logger->warning('Falha ao enviar comprovante de participação por e-mail.');

            return $this->responder($request, $token, 'Não foi possível enviar o comprovante. Tente novamente.', 503);
        }

        return $this->responder($request, $token, 'Comprovante enviado por e-mail.', 200);
    }

    private function responder(Request $request, string $token, string $mensagem, int $status): Response
    {
        if (str_contains($request->headers->get('Accept', ''), 'application/json')) {
            return new JsonResponse(['ok' => $status === 200, 'mensagem' => $mensagem], $status);
        }
        $this->addFlash($status === 200 ? CedulaController::FLASH_OK : CedulaController::FLASH_ERRO, $mensagem);

        return $this->redirectToRoute('voto_assembleia_cedula', ['token' => $token], 303);
    }
}
