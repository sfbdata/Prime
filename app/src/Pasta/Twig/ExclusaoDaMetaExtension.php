<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Service\Tenant\TenantContext;
use App\Tarefa\UseCase\ExcluirTarefaUseCase;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `meta_pode_excluir(tarefa)`: o item "Excluir" do ⋮ da meta (PMC-030) aparece só quando a
 * exclusão passaria no servidor — a decisão é a do próprio `ExcluirTarefaUseCase::podeExcluir`
 * (criador da meta, dentro da janela após a criação, escritório da sessão), não uma cópia dela.
 *
 * Mora aqui pelo mesmo motivo do `metas_ultimos_alertas()`: a aba Metas é um parcial e não
 * precisa de variável nova no controller. Sem usuário ou escritório na sessão: não pode.
 */
final class ExclusaoDaMetaExtension extends AbstractExtension
{
    public function __construct(
        private readonly ExcluirTarefaUseCase $excluirTarefa,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('meta_pode_excluir', $this->podeExcluir(...)),
        ];
    }

    public function podeExcluir(Tarefa $tarefa): bool
    {
        $usuario = $this->security->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null) {
            return false;
        }

        return $this->excluirTarefa->podeExcluir($tarefa, $usuario, $tenant);
    }
}
