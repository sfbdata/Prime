<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Pasta\DTO\UltimoAlertaDaMetaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\UltimosAlertasDasMetas;
use App\Service\Tenant\TenantContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `metas_ultimos_alertas(pasta)`: o último alerta do sino de cada meta da pasta,
 * no escritório da sessão. Mora aqui (e não no controller) pelo mesmo motivo do
 * `pasta_favorita()`: a aba Metas é um parcial e não precisa de variável nova.
 *
 * Sem escritório na sessão, ou pasta de outro escritório, ninguém foi alertado:
 * devolve vazio.
 */
final class AlertasDasMetasExtension extends AbstractExtension
{
    public function __construct(
        private readonly UltimosAlertasDasMetas $alertas,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('metas_ultimos_alertas', $this->ultimosAlertas(...)),
        ];
    }

    /** @return array<int, UltimoAlertaDaMetaOutput> */
    public function ultimosAlertas(Pasta $pasta): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $daPasta = $pasta->getTenant();
        if ($tenant === null || $daPasta === null || $daPasta->getId() !== $tenant->getId()) {
            return [];
        }

        return $this->alertas->daPasta($pasta, $tenant);
    }
}
