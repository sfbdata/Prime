<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Pasta\DTO\PaginaDoPushCompartilhadoOutput;
use App\Pasta\UseCase\AbrirLinkExternoDoPushUseCase;
use App\Pasta\UseCase\PedirRenovacaoDoLinkUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A página pública do link externo do Push (spec push-link-externo §3-4): `/p/<endereco>`, SEM login
 * (`access_control` PUBLIC_ACCESS), fora das guardas de termo e de escritório, com o `TenantFilter`
 * desligado (`PushCompartilhadoSemTenantFilterListener`): toda consulta usa o tenant DO LINK.
 *
 * Todas as rotas passam pelo limitador `push_publico` (30 por minuto por IP): estourou, 429 com a
 * mesma página. Toda resposta sai com `noindex`, sem cache e sem Referer.
 *
 * O código certo mostra o andamento NA PRÓPRIA resposta do POST, sem guardar nada na sessão:
 * recarregar a página pede o código de novo, como no desenho (o `liberado` é estado da tela).
 */
#[Route('/p/{endereco}', requirements: ['endereco' => 'bluejus-[a-z0-9-]{3,70}'])]
final class PushCompartilhadoController extends AbstractController
{
    public function __construct(
        private readonly AbrirLinkExternoDoPushUseCase $abrir,
        private readonly RateLimiterFactoryInterface $pushPublicoLimiter,
    ) {
    }

    #[Route('', name: 'push_compartilhado', methods: ['GET'])]
    public function ver(Request $request, string $endereco): Response
    {
        if ($this->estourouOLimite($request)) {
            return $this->limite();
        }

        return $this->pagina($this->abrir->executar($endereco));
    }

    #[Route('', name: 'push_compartilhado_codigo', methods: ['POST'])]
    public function codigo(Request $request, string $endereco): Response
    {
        if ($this->estourouOLimite($request)) {
            return $this->limite();
        }
        if (!$this->isCsrfTokenValid('push_publico_' . $endereco, (string) $request->request->get('_token'))) {
            return $this->semCache($this->redirectToRoute('push_compartilhado', ['endereco' => $endereco]));
        }

        return $this->pagina($this->abrir->executar($endereco, (string) $request->request->get('codigo', '')));
    }

    #[Route('/renovacao', name: 'push_compartilhado_renovacao', methods: ['POST'])]
    public function renovacao(Request $request, string $endereco, PedirRenovacaoDoLinkUseCase $pedir): Response
    {
        if ($this->estourouOLimite($request)) {
            return $this->limite();
        }
        if ($this->isCsrfTokenValid('push_publico_' . $endereco, (string) $request->request->get('_token'))) {
            $pedir->executar(
                $endereco,
                (string) $request->request->get('nome', ''),
                (string) $request->request->get('mensagem', ''),
            );
        }

        // Pedido aceito ou não, a página volta ao estado real (pedido enviado, recusado ou o formulário).
        return $this->semCache($this->redirectToRoute('push_compartilhado', ['endereco' => $endereco]));
    }

    private function estourouOLimite(Request $request): bool
    {
        return !$this->pushPublicoLimiter->create($request->getClientIp())->consume()->isAccepted();
    }

    private function pagina(PaginaDoPushCompartilhadoOutput $pagina): Response
    {
        $status = $pagina->estado === PaginaDoPushCompartilhadoOutput::NAO_ENCONTRADO
            ? Response::HTTP_NOT_FOUND
            : Response::HTTP_OK;

        return $this->semCache($this->render('push_compartilhado/show.html.twig', [
            'pagina' => $pagina,
            'limite' => false,
            'agora' => new \DateTimeImmutable(),
        ], new Response('', $status)));
    }

    private function limite(): Response
    {
        return $this->semCache($this->render('push_compartilhado/show.html.twig', [
            'pagina' => null,
            'limite' => true,
            'agora' => new \DateTimeImmutable(),
        ], new Response('', Response::HTTP_TOO_MANY_REQUESTS)));
    }

    private function semCache(Response $resposta): Response
    {
        $resposta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $resposta->headers->set('Cache-Control', 'no-store, private');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }
}
