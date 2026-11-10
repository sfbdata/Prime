<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * As rotas públicas do signatário (`assinatura_eletronica_publica` e `assinatura_eletronica_publica_*`, contrato da
 * etapa 2, seção 5.2).
 *
 * Na requisição, desliga o `TenantFilter`: a página é de quem NÃO é do escritório, e quem abre o link logado (o próprio
 * advogado ou alguém de outro escritório) teria o filtro ligado no escritório DA SESSÃO pelo `TenantFilterListener`
 * (prioridade 5). Aqui, depois dele e do `TenantUrlScopeListener` (4), o filtro sai; quem escopa é o código: o
 * signatário é achado pelo hash do token e o escritório sai do próprio documento (como o link externo do Push). O nome
 * da rota é conferido com fronteira (a rota da página ou o prefixo com o sublinhado): uma rota de nome parecido, como
 * `assinatura_eletronica_publicacao_x`, não desliga o filtro.
 *
 * Na resposta, os cabeçalhos que tiram a página de buscadores, de cache e do Referer, decididos pelo caminho: toda
 * resposta cujo caminho começa com `/assinar` os leva, inclusive o 404 de `/assinar` sem barra e o 405 de método errado
 * (que não chegam a ter rota), além das rotas públicas.
 */
final class AssinaturaPublicaListener
{
    public const ROTA_DA_PAGINA = 'assinatura_eletronica_publica';
    public const PREFIXO_DAS_ROTAS = 'assinatura_eletronica_publica_';
    public const PREFIXO_DO_CAMINHO = '/assinar';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
    public function aoReceber(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !self::ehRotaPublica($event->getRequest()->attributes->get('_route'))) {
            return;
        }

        $filtros = $this->em->getFilters();
        if ($filtros->isEnabled('tenant')) {
            $filtros->disable('tenant');
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function aoResponder(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && self::ehRespostaPublica($event->getRequest())) {
            self::proteger($event->getResponse());
        }
    }

    /** Os cabeçalhos da página do signatário; a prévia do escritório (rota interna) também os usa. */
    public static function proteger(Response $resposta): Response
    {
        $resposta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $resposta->headers->set('Cache-Control', 'no-store');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }

    /** A rota da página ou uma do prefixo com o sublinhado; nome parecido (sem a fronteira) não conta. */
    public static function ehRotaPublica(mixed $rota): bool
    {
        return \is_string($rota) && ($rota === self::ROTA_DA_PAGINA || str_starts_with($rota, self::PREFIXO_DAS_ROTAS));
    }

    private static function ehRespostaPublica(Request $request): bool
    {
        return self::ehRotaPublica($request->attributes->get('_route'))
            || str_starts_with(rawurldecode($request->getPathInfo()), self::PREFIXO_DO_CAMINHO);
    }
}
