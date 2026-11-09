<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * As rotas públicas do signatário (`assinatura_eletronica_publica*`, contrato da etapa 2, seção 5.2).
 *
 * Na requisição, desliga o `TenantFilter`: a página é de quem NÃO é do escritório, e quem abre o link logado (o próprio
 * advogado ou alguém de outro escritório) teria o filtro ligado no escritório DA SESSÃO pelo `TenantFilterListener`
 * (prioridade 5). Aqui, depois dele e do `TenantUrlScopeListener` (4), o filtro sai; quem escopa é o código: o
 * signatário é achado pelo hash do token e o escritório sai do próprio documento (como o link externo do Push).
 *
 * Na resposta (inclusive 404, 422 e 429), os cabeçalhos que tiram a página de buscadores, de cache e do Referer.
 */
final class AssinaturaPublicaListener
{
    public const PREFIXO_DAS_ROTAS = 'assinatura_eletronica_publica';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
    public function aoReceber(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !self::ehPublica($event->getRequest()->attributes->get('_route'))) {
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
        if ($event->isMainRequest() && self::ehPublica($event->getRequest()->attributes->get('_route'))) {
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

    private static function ehPublica(mixed $rota): bool
    {
        return \is_string($rota) && str_starts_with($rota, self::PREFIXO_DAS_ROTAS);
    }
}
