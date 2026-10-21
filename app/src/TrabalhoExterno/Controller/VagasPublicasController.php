<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\UseCase\InscreverInteressadoUseCase;
use App\TrabalhoExterno\UseCase\MontarPaginaDeVagasUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A página pública de vagas (spec VS 18.10.26 §4.2), SEM login (`^/vagas-juridicas` em
 * PUBLIC_ACCESS no security.yaml). Nada de escritório, contato nem remuneração sai daqui: quem
 * decide o recorte é o `MontarPaginaDeVagasUseCase`. A inscrição nos alertas tem CSRF da própria
 * página e limite por IP (confiável em produção só com o IP real do cliente).
 */
#[Route('/vagas-juridicas')]
final class VagasPublicasController extends AbstractController
{
    private const CSRF = 'vagas_alertas';

    public function __construct(
        private readonly MontarPaginaDeVagasUseCase $montar,
        // Pelo nome do argumento o container entrega o limitador `vagas_alertas` (framework.yaml).
        private readonly RateLimiterFactoryInterface $vagasAlertasLimiter,
    ) {
    }

    #[Route('', name: 'trabalho_externo_vagas_publicas', methods: ['GET'])]
    public function geral(): Response
    {
        return $this->pagina(null);
    }

    #[Route('/{token}', name: 'trabalho_externo_vagas_link', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{20,64}'])]
    public function link(string $token): Response
    {
        return $this->pagina($token);
    }

    #[Route('/alertas', name: 'trabalho_externo_vagas_alertas', methods: ['POST'], priority: 10)]
    public function alertas(Request $request, InscreverInteressadoUseCase $inscrever): JsonResponse
    {
        if (!$this->vagasAlertasLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return $this->json(['ok' => false, 'mensagem' => 'Muitas tentativas. Tente de novo daqui a pouco.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $corpo = json_decode($request->getContent() ?: '[]', true);
        $corpo = is_array($corpo) ? $corpo : [];
        if (!$this->isCsrfTokenValid(self::CSRF, (string) ($corpo['_token'] ?? ''))) {
            return $this->json(['ok' => false, 'mensagem' => 'A página expirou. Recarregue e tente de novo.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $inscrever->executar(
                is_string($corpo['email'] ?? null) ? $corpo['email'] : '',
                is_string($corpo['uf'] ?? null) ? $corpo['uf'] : '',
                is_array($corpo['areas'] ?? null) ? array_values(array_filter($corpo['areas'], 'is_string')) : [],
                ($corpo['lgpd'] ?? false) === true,
                is_string($corpo['token'] ?? null) ? $corpo['token'] : null,
            );
        } catch (DadosInvalidos $e) {
            return $this->json(['ok' => false, 'mensagem' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true]);
    }

    private function pagina(?string $token): Response
    {
        $resposta = $this->render('trabalho_externo/vagas_publicas.html.twig', [
            'pagina' => $this->montar->executar($token),
            'token' => $token,
            'csrf' => self::CSRF,
        ]);
        // O link carrega um token: não vai para o Referer de quem clicar para fora.
        $resposta->headers->set('Referrer-Policy', 'no-referrer');
        $resposta->headers->set('X-Robots-Tag', 'noindex');

        return $resposta;
    }
}
