<?php

declare(strict_types=1);

namespace App\Master\EventListener;

use App\Shared\Http\NonceDaCsp;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Content-Security-Policy das páginas do Painel do Master (defesa em profundidade contra XSS).
 *
 *  - Mapas (`master_mapa_*`, páginas próprias do iframe): política ESTRITA. Script só do próprio
 *    domínio, nada inline, nenhuma conexão para fora (o IBGE e a OAB são chamados pelo servidor).
 *    `style-src 'unsafe-inline'` fica porque o d3 e o desenho escrevem estilo em atributo.
 *  - Painel (`master_painel`) e editor do blog (`master_blog_artigos_novo` e `_editar`): estendem
 *    `base.html.twig`. Script só do próprio domínio (jQuery, Bootstrap, OverlayScrollbars, Select2 e
 *    AdminLTE saem de public/js/vendor desde a auditoria I-xss 5.08) e o script inline do layout só
 *    com o nonce da requisição (`NonceDaCsp`): sem `'unsafe-inline'` e sem CDN em `script-src`, um
 *    `<script>` ou `on*=""` injetado não roda. O CSS e as fontes da moldura (Bootstrap Icons, Font
 *    Awesome, AdminLTE, Select2, OverlayScrollbars) continuam no jsDelivr, e o `style=""` inline
 *    continua liberado. Conexões, frames, objetos, base e formulários só no próprio domínio.
 *  - Prévia do blog (`master_blog_artigos_previa`, página própria sem a moldura e sem script, a única
 *    que imprime HTML guardado com |raw): nada de script, rede ou formulário; só o estilo inline da
 *    página e as imagens do próprio domínio.
 *  - JSON (`master_dados_*`, `master_ibge_*`, `master_fontes_*`): `nosniff`.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class CabecalhosDeSegurancaDoMaster
{
    public const CSP_MAPA = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
        . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'";

    /** `%s` é o nonce da requisição (ver politicaDoPainel). */
    public const CSP_PAINEL = "default-src 'self'; script-src 'self' 'nonce-%s'; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' data: https://cdn.jsdelivr.net; img-src 'self' data: blob: https:; "
        . "connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public const CSP_PREVIA_DO_BLOG = "default-src 'none'; script-src 'none'; style-src 'unsafe-inline'; img-src 'self'; "
        . "base-uri 'none'; form-action 'none'; frame-ancestors 'self'";

    /** Páginas que estendem a base e recebem a CSP do painel. */
    private const ROTAS_DO_PAINEL = ['master_painel', 'master_blog_artigos_novo', 'master_blog_artigos_editar'];

    public function __construct(private readonly NonceDaCsp $nonce)
    {
    }

    public static function politicaDoPainel(string $nonce): string
    {
        return sprintf(self::CSP_PAINEL, $nonce);
    }

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

        if ($rota === 'master_blog_artigos_previa') {
            $cabecalhos->set('Content-Security-Policy', self::CSP_PREVIA_DO_BLOG);

            return;
        }

        if (\in_array($rota, self::ROTAS_DO_PAINEL, true)) {
            $cabecalhos->set('Content-Security-Policy', self::politicaDoPainel($this->nonce->nonce()));
        }
    }
}
