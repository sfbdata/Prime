<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\UseCase\InscreverInteressadoUseCase;
use App\TrabalhoExterno\UseCase\MontarPaginaDeVagasUseCase;
use App\TrabalhoExterno\UseCase\RegistrarMetricaPublicaUseCase;
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
 * página e limite por IP (confiável em produção só com o IP real do cliente). A visita pelo link e o
 * clique em "Criar conta" viram contadores anônimos por dia (spec §4.9), sem nada da pessoa.
 */
#[Route('/vagas-juridicas')]
final class VagasPublicasController extends AbstractController
{
    private const CSRF = 'vagas_alertas';

    public function __construct(
        private readonly MontarPaginaDeVagasUseCase $montar,
        // Pelo nome do argumento o container entrega o limitador `vagas_alertas` (framework.yaml).
        private readonly RateLimiterFactoryInterface $vagasAlertasLimiter,
        // E o `vagas_cta` (framework.yaml), para o contador de cliques em "Criar conta".
        private readonly RateLimiterFactoryInterface $vagasCtaLimiter,
        // E o `vagas_visita`, por IP e link: acima do teto a página abre, só não conta.
        private readonly RateLimiterFactoryInterface $vagasVisitaLimiter,
        private readonly RegistrarMetricaPublicaUseCase $metricas,
    ) {
    }

    #[Route('', name: 'trabalho_externo_vagas_publicas', methods: ['GET'])]
    public function geral(): Response
    {
        return $this->pagina(null);
    }

    #[Route('/{token}', name: 'trabalho_externo_vagas_link', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{20,64}'])]
    public function link(Request $request, string $token): Response
    {
        $resposta = $this->pagina($token);
        if ($request->isMethod('GET') && $this->vagasVisitaLimiter->create($request->getClientIp() . '|' . $token)->consume()->isAccepted()) {
            $this->metricas->executar(RegistrarMetricaPublicaUseCase::VISITA, $token, (string) $request->headers->get('User-Agent', ''));
        }

        return $resposta;
    }

    /**
     * Clique em "Criar conta" na página (com ou sem token), por `navigator.sendBeacon`: sem sessão e sem
     * CSRF, porque não grava nada da pessoa; o limite por IP segura a enxurrada e só conta o que vem da
     * própria página ({@see daPropriaOrigem()}). Sempre 204 (o navegador não lê a resposta); só o excesso
     * responde 429.
     */
    #[Route('/cta', name: 'vagas_publicas_cta', methods: ['POST'], priority: 10)]
    public function cta(Request $request): Response
    {
        if (!$this->vagasCtaLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return new Response(null, Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (!self::daPropriaOrigem($request)) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }
        $corpo = json_decode($request->getContent() ?: '[]', true);
        $token = is_array($corpo) && is_string($corpo['token'] ?? null) ? $corpo['token'] : null;
        $this->metricas->executar(RegistrarMetricaPublicaUseCase::CLIQUE, $token, (string) $request->headers->get('User-Agent', ''));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * O clique veio da própria página: `Sec-Fetch-Site: same-origin` (o navegador põe e o script não
     * falsifica); sem esse cabeçalho, `Origin` ou `Referer` com o mesmo host do pedido.
     */
    private static function daPropriaOrigem(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null) {
            return $site === 'same-origin';
        }
        $origem = $request->headers->get('Origin') ?? $request->headers->get('Referer');

        return $origem !== null && parse_url($origem, PHP_URL_HOST) === $request->getHost();
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
