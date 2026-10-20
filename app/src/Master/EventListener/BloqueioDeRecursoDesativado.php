<?php

declare(strict_types=1);

namespace App\Master\EventListener;

use App\Master\DTO\EstadoDoRecursoOutput;
use App\Master\Service\CatalogoDeRecursos;
use App\Master\Service\RecursosDoSistema;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Guarda de rota das telas desligadas pelo Master (spec docs/specs/perfis-1.2-telas-e-recursos.md, R5 e R6).
 *
 * Só a requisição principal, só rota mapeada no {@see CatalogoDeRecursos} (rota fora do mapa nunca é
 * bloqueada). Com a chave desligada:
 *  - o Master passa (vê a tela com a faixa vermelha do base.html.twig);
 *  - chamada de API (não-GET ou `Accept: application/json`) recebe 503 JSON com a mensagem;
 *  - o resto recebe a página "em preparação" (503).
 *
 * Prioridade 3: depois do firewall (8), do aceite dos Termos (7), do vínculo com o escritório (6), do
 * filtro de tenant (5) e do escopo de URL (4), para o usuário já estar carregado e a sessão já ter
 * passado pelos portões de sempre.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
final class BloqueioDeRecursoDesativado
{
    public const MENSAGEM_PADRAO = 'Estamos finalizando esta tela para ela chegar com tudo funcionando. Volte em breve.';

    public function __construct(
        private readonly RecursosDoSistema $recursos,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $chave = CatalogoDeRecursos::chaveDaRota((string) $request->attributes->get('_route', ''));
        if ($chave === null || $this->recursos->estaAtivo($chave) || $this->recursos->usuarioEhMaster()) {
            return;
        }

        $event->setResponse($this->respostaDeBloqueio($request, $this->recursos->estado($chave)));
    }

    private function respostaDeBloqueio(Request $request, EstadoDoRecursoOutput $estado): Response
    {
        $titulo = CatalogoDeRecursos::titulo($estado->chave) . ' em preparação';
        $mensagem = $estado->motivo ?? self::MENSAGEM_PADRAO;

        if ($this->ehChamadaDeApi($request)) {
            return new JsonResponse(
                ['erro' => $titulo . '. ' . $mensagem, 'titulo' => $titulo, 'mensagem' => $mensagem, 'previsao' => $estado->previsao()],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new Response($this->twig->render('recurso/em_preparacao.html.twig', [
            'titulo'   => $titulo,
            'mensagem' => $mensagem,
            'previsao' => $estado->previsao(),
        ]), Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function ehChamadaDeApi(Request $request): bool
    {
        return !$request->isMethodSafe()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json')
            || $request->isXmlHttpRequest();
    }
}
