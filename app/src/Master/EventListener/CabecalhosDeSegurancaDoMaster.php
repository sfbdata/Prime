<?php

declare(strict_types=1);

namespace App\Master\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Content-Security-Policy das páginas do Painel do Master (defesa em profundidade contra XSS).
 *
 *  - Mapas (`master_mapa_*`, páginas próprias do iframe): política ESTRITA. Script só do próprio
 *    domínio, nada inline, nenhuma conexão para fora (o IBGE e a OAB são chamados pelo servidor).
 *    `style-src 'unsafe-inline'` fica porque o d3 e o desenho escrevem estilo em atributo.
 *  - Painel (`master_painel`): estende `base.html.twig`, que ainda tem script inline e carrega
 *    jQuery/Bootstrap/AdminLTE de CDN. A política aqui fecha o que dá sem quebrar a moldura do
 *    sistema: conexões, frames, objetos, base e formulários só no próprio domínio, e script só do
 *    próprio domínio e das duas CDNs já usadas. `'unsafe-inline'` em script continua até a base
 *    perder o script inline (fora do escopo desta etapa).
 *  - JSON (`master_dados_*`, `master_ibge_*`, `master_fontes_*`): `nosniff`.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class CabecalhosDeSegurancaDoMaster
{
    public const CSP_MAPA = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
        . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'";

    public const CSP_PAINEL = "default-src 'self'; script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' data: https://cdn.jsdelivr.net; img-src 'self' data: blob: https:; "
        . "connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $rota = (string) $event->getRequest()->attributes->get('_route', '');
        if (!str_starts_with($rota, 'master_')) {
            return;
        }

        $cabecalhos = $event->getResponse()->headers;
        $cabecalhos->set('X-Content-Type-Options', 'nosniff');

        if (str_starts_with($rota, 'master_mapa_')) {
            $cabecalhos->set('Content-Security-Policy', self::CSP_MAPA);

            return;
        }

        if ($rota === 'master_painel') {
            $cabecalhos->set('Content-Security-Policy', self::CSP_PAINEL);
        }
    }
}
