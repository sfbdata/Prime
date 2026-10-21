<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaComentarioDestaqueRepository;
use App\Service\Tenant\TenantContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Os destaques dos comentários da pasta para a tela (spec pasta-destacar-comentario.md): aba => [comentário => cor].
 * Só a tela da pasta chama, e ela já exige ver a pasta (tenant e cadeado); a pasta de outro escritório vem vazia.
 */
final class DestaqueDoComentarioExtension extends AbstractExtension
{
    public function __construct(
        private readonly PastaComentarioDestaqueRepository $destaques,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('destaques_dos_comentarios', $this->destaquesDosComentarios(...)),
        ];
    }

    /** @return array<string, array<int, string>> */
    public function destaquesDosComentarios(Pasta $pasta): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            return [];
        }

        return $this->destaques->mapaDaPasta($pasta, $tenant);
    }
}
