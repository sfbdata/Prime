<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Pasta\Entity\Pasta;
use App\Pasta\Service\CobrancasDoChecklistDaPasta;
use App\Service\Tenant\TenantContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cobrancas_do_checklist(pasta)`: os selos "cobrado Nx" dos itens pendentes do checklist (desenho 02, dc
 * 2311). Pasta de outro escritório que o da sessão não mostra nada (mesma guarda do `documentos_sugeridos`).
 */
final class CobrancaDoChecklistExtension extends AbstractExtension
{
    public function __construct(
        private readonly CobrancasDoChecklistDaPasta $cobrancas,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('cobrancas_do_checklist', $this->selos(...))];
    }

    /** @return array<int, array{texto: string, dica: string, vermelho: bool}> */
    public function selos(Pasta $pasta): array
    {
        $tenantId = $this->tenantContext->getCurrentTenant()?->getId();
        if ($tenantId === null || $pasta->getTenant()?->getId() !== $tenantId) {
            return [];
        }

        return $this->cobrancas->selos($pasta);
    }
}
