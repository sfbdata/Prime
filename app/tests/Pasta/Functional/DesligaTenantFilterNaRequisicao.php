<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Desliga o TenantFilter DENTRO da próxima requisição, para o teste provar o guarda explícito de
 * posse (busca escopada / prólogo de tenant) e não a outra barreira.
 *
 * Desligar o filtro antes do `request()` não basta: o `TenantFilterListener` (kernel.request,
 * prioridade 5) o religa no começo de toda requisição com tenant na sessão. Por isso um ouvinte
 * de prioridade bem baixa o desliga DEPOIS dele (e do `TenantUrlScopeListener`, prioridade 4),
 * ainda antes de o controller e os resolvers rodarem.
 *
 * O `clear()` do EntityManager tira do identity map o que o teste acabou de gravar: sem ele, o
 * `find()` devolveria a entidade da memória mesmo com o filtro LIGADO, e o cenário não dependeria
 * do filtro de um jeito ou de outro. Depois do `clear()`, quem quiser as entidades usa os ids.
 *
 * Vale só para a PRIMEIRA requisição do cliente (sem reboot do kernel): o ouvinte é registrado no
 * dispatcher do container atual. Cada teste faz uma requisição só.
 */
trait DesligaTenantFilterNaRequisicao
{
    private bool $tenantFilterFoiDesligado = false;

    private function desligarTenantFilterNaProximaRequisicao(): void
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $this->tenantFilterFoiDesligado = false;

        $container->get('event_dispatcher')->addListener(
            KernelEvents::REQUEST,
            function (RequestEvent $evento) use ($em): void {
                if (!$evento->isMainRequest()) {
                    return;
                }

                $filtros = $em->getFilters();
                if ($filtros->isEnabled('tenant')) {
                    $filtros->disable('tenant');
                    $this->tenantFilterFoiDesligado = true;
                }
            },
            -1024,
        );

        $em->clear();
    }

    /**
     * A prova de que o cenário rodou como pretendido: o filtro estava ligado (havia tenant na
     * sessão) e foi desligado antes do controller. Sem isso, um 404 poderia ser do filtro.
     */
    private function assertTenantFilterFoiDesligado(): void
    {
        self::assertTrue(
            $this->tenantFilterFoiDesligado,
            'O TenantFilter precisava estar DESLIGADO na requisição — senão o teste prova a outra barreira.',
        );
    }
}
