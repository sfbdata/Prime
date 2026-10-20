<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\Service\MontadorDeEmail;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pixel de abertura e link rastreado dos e-mails do Carteiro (spec `comercial-e-emails.md` §3.8 e §3.9).
 *
 * Rotas PÚBLICAS (uma linha própria cada no security.yaml), sem login e sem sessão: o controller não toca a sessão.
 * Limitador `comercial_rastreio` por IP (600 por minuto; até o `SYMFONY_TRUSTED_PROXIES` estar confirmado na VPS, o IP
 * é o do nginx e o limite vale como teto global).
 *   - abertura: responde SEMPRE o mesmo GIF 1x1, inclusive para token inválido (não revela se o token existe);
 *   - clique: confere a assinatura do caminho com a consulta (token e `u`); errada, 404 sem redirecionar.
 */
final class RastreioController extends AbstractController
{
    /** GIF transparente de 1x1. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    private const PADRAO_DO_TOKEN = '/^[a-f0-9]{32}$/';

    public function __construct(
        private readonly EnvioDeEmailRepository $envios,
        private readonly UriSigner $assinadorDeClique,
        private readonly RateLimiterFactoryInterface $comercialRastreioLimiter,
        private readonly ClockInterface $relogio,
    ) {
    }

    #[Route('/e/a/{token}.gif', name: MontadorDeEmail::ROTA_ABERTURA, methods: ['GET'])]
    public function abertura(Request $request, string $token): Response
    {
        $this->limitar($request);
        if (preg_match(self::PADRAO_DO_TOKEN, $token) === 1) {
            $this->envios->registrarAbertura($token, $this->relogio->now());
        }

        return new Response((string) base64_decode(self::GIF, true), Response::HTTP_OK, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    #[Route('/e/c/{token}', name: MontadorDeEmail::ROTA_CLIQUE, methods: ['GET'])]
    public function clique(Request $request, string $token): Response
    {
        $this->limitar($request);
        $destino = (string) $request->query->get('u', '');
        if (!$this->assinadorDeClique->check($request->getRequestUri()) || preg_match('#^https?://#i', $destino) !== 1) {
            throw new NotFoundHttpException('Link inválido.');
        }
        if (preg_match(self::PADRAO_DO_TOKEN, $token) === 1) {
            $this->envios->registrarClique($token, $this->relogio->now());
        }

        $resposta = new RedirectResponse($destino, Response::HTTP_FOUND);
        $resposta->headers->set('Referrer-Policy', 'no-referrer');
        $resposta->headers->set('X-Robots-Tag', 'noindex');

        return $resposta;
    }

    private function limitar(Request $request): void
    {
        if (!$this->comercialRastreioLimiter->create($request->getClientIp() ?? 'desconhecido')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(60, 'Muitas requisições.');
        }
    }
}
