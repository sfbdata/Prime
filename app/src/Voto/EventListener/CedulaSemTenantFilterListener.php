<?php

declare(strict_types=1);

namespace App\Voto\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Desliga o `TenantFilter` na cédula pública da assembleia (`voto_assembleia_cedula` e `voto_assembleia_cedula_votar`, `/votar/{token}`).
 *
 * A página é do morador, que não é do escritório. Sem login o filtro já fica desligado; mas quem abre o link logado
 * (alguém da equipe conferindo o link, ou um morador que tem conta em outro escritório) teria o filtro ligado no
 * tenant DA SESSÃO pelo `TenantFilterListener` (prioridade 5) e veria a cédula pela metade. Aqui, depois dele e do
 * `TenantUrlScopeListener` (4), o filtro sai; quem escopa é o código, que passa o escritório da assembleia em toda
 * consulta. Mesmo modelo do `App\Pasta\EventListener\PushCompartilhadoSemTenantFilterListener`.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
final class CedulaSemTenantFilterListener
{
    /**
     * Nomes exatos, não prefixo: uma rota da equipe que começasse com o mesmo nome (uma prévia da cédula, por exemplo)
     * rodaria logada com o filtro de escritório desligado.
     */
    public const ROTAS = [
        'voto_assembleia_cedula', 'voto_assembleia_cedula_votar',
        'voto_assembleia_cedula_comprovante_email',
        'voto_assembleia_portal',
        'voto_assembleia_portal_localizar',
        'voto_assembleia_portal_solicitar',
        'voto_assembleia_portal_confirmar',
        'voto_assembleia_portal_reiniciar',
        'voto_assembleia_portal_sair_terminal',
        'voto_assembleia_portal_comprovante',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $rota = (string) $event->getRequest()->attributes->get('_route', '');
        if (!in_array($rota, self::ROTAS, true)) {
            return;
        }

        $filtros = $this->em->getFilters();
        if ($filtros->isEnabled('tenant')) {
            $filtros->disable('tenant');
        }
    }
}
