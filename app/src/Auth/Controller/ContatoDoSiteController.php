<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\ContatoDoSiteInput;
use App\Auth\UseCase\EnviarMensagemSuporteUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Recebe o formulário de Contato do site institucional (site estático no mesmo domínio). PÚBLICA (PUBLIC_ACCESS no
 * security.yaml), sem login e sem CSRF: o site estático não tem sessão para emitir token. A defesa é o honeypot
 * "website" e a cota por IP do mesmo limitador do "Falar com suporte" (5 por hora), numa chave própria do site.
 *
 * Ordem: honeypot, validação e só então a cota do IP, para um envio inválido não gastar a cota. Resposta JSON:
 * {ok: true} ou {ok: false, erro}.
 */
final class ContatoDoSiteController extends AbstractController
{
    public function __construct(
        private readonly EnviarMensagemSuporteUseCase $enviarMensagem,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactoryInterface $suporteEntradaLimiter,
    ) {
    }

    #[Route('/contato', name: 'auth_contato_do_site', methods: ['POST'])]
    public function enviar(Request $request): JsonResponse
    {
        $dados = $request->request->all();
        $honeypot = $dados['website'] ?? '';
        if (!is_scalar($honeypot) || trim((string) $honeypot) !== '') {
            return $this->json(['ok' => true]); // honeypot: robô recebe "ok" e nada é gravado
        }

        $input = ContatoDoSiteInput::doFormulario($dados, (string) $request->getClientIp(), $request->headers->get('User-Agent'));
        $violacoes = $this->validator->validate($input);
        if (\count($violacoes) > 0) {
            return $this->erro((string) $violacoes[0]->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->suporteEntradaLimiter->create('site:' . (string) $request->getClientIp())->consume()->isAccepted()) {
            return $this->erro('Muitas tentativas. Tente de novo mais tarde.', Response::HTTP_TOO_MANY_REQUESTS);
        }
        $this->enviarMensagem->executar($input->paraMensagemSuporte());

        return $this->json(['ok' => true]);
    }

    private function erro(string $mensagem, int $status): JsonResponse
    {
        return $this->json(['ok' => false, 'erro' => $mensagem], $status);
    }
}
