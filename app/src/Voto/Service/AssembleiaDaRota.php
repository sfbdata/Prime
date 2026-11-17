<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Service\Tenant\TenantContext;
use App\Voto\Entity\Assembleia;
use App\Voto\Repository\AssembleiaRepository;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;

/**
 * De onde sai a assembleia de uma requisição, num ponto só: o argumento `Assembleia` da action ou, nas rotas do Voto
 * que levam só o número (`int $id`), o `id` da rota. Usado pela `BarreiraDaEquipeListener`.
 *
 * Pelo id a busca é por id E escritório da sessão (guarda IDOR): id de outro escritório não acha nada, e a action
 * responde o 404 dela. Sem escritório na sessão, devolve nada. O argumento chega já filtrado pelo `TenantFilter`;
 * mesmo assim confere o escritório da sessão.
 */
final class AssembleiaDaRota
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function resolver(ControllerArgumentsEvent $event): ?Assembleia
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (null === $tenant) {
            return null;
        }

        foreach ($event->getArguments() as $argumento) {
            if ($argumento instanceof Assembleia) {
                return $argumento->getTenant()->getId() === $tenant->getId() ? $argumento : null;
            }
        }

        $id = $event->getRequest()->attributes->get('id');

        return \is_scalar($id) && ctype_digit((string) $id) ? $this->assembleias->buscarDoEscritorio((int) $id, $tenant) : null;
    }
}
