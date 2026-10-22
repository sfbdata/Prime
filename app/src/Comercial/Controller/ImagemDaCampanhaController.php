<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\Armazenamento\ChavesDeComercial;
use App\Comercial\Campanha\ImagensDaCampanha;
use App\Comercial\Repository\ImagemDaCampanhaRepository;
use App\Shared\Http\EntregaDeArquivo;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O endereço público da imagem da campanha do modo simples (spec `comercial-e-emails.md` §12, F3i): é o `src` do
 * `<img>` que vai no e-mail, aberto pelo programa de e-mail de quem recebe, sem login.
 *
 * Rota PÚBLICA (linha própria no security.yaml), sem sessão: o controller não toca a sessão. O mesmo limitador dos
 * outros endereços públicos dos e-mails (`comercial_rastreio`, por IP). O token decide tudo antes de montar a chave
 * (INV-5): inexistente, vencido ou com a extensão de outro tipo dá 404, sem dizer qual dos três. A resposta leva o tipo
 * gravado (o que o servidor regravou), `nosniff`, cache público de um dia e `noindex`.
 */
final class ImagemDaCampanhaController extends AbstractController
{
    public function __construct(
        private readonly ImagemDaCampanhaRepository $imagens,
        private readonly EntregaDeArquivo $entrega,
        private readonly RateLimiterFactoryInterface $comercialRastreioLimiter,
        private readonly ClockInterface $relogio,
    ) {
    }

    #[Route('/e/i/{token}.{ext}', name: ImagensDaCampanha::ROTA, requirements: ['token' => '[a-f0-9]{32}', 'ext' => 'jpg|png'], methods: ['GET'])]
    public function imagem(Request $request, string $token, string $ext): Response
    {
        if (!$this->comercialRastreioLimiter->create($request->getClientIp() ?? 'desconhecido')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(60, 'Muitas requisições.');
        }
        $imagem = $this->imagens->vigente($token, $this->relogio->now());
        if ($imagem === null || $imagem->extensao() !== $ext) {
            throw new NotFoundHttpException('Imagem não encontrada.');
        }

        $resposta = $this->entrega->resposta(ChavesDeComercial::imagem($imagem), 'imagem.' . $ext, inline: true);
        $resposta->headers->set('Content-Type', $imagem->getMime());
        $resposta->headers->set('X-Content-Type-Options', 'nosniff');
        $resposta->headers->set('Cache-Control', 'public, max-age=86400');
        $resposta->headers->set('X-Robots-Tag', 'noindex');
        // O conteúdo não depende de quem pede: o "private" automático do Symfony (um ouvinte global leu a sessão) não vale aqui.
        $resposta->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $resposta;
    }
}
