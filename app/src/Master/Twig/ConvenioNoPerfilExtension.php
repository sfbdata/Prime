<?php

declare(strict_types=1);

namespace App\Master\Twig;

use App\Master\Service\ChaveDoConvenio;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\RegraDosPrimarios;
use Psr\Log\LoggerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `convenio_no_perfil()`: se o Meu Perfil mostra o campo "Tenho um código de convênio" (parcial
 * `profile/_convenio_no_perfil.html.twig`; spec docs/specs/convenios.md, §5.3 e §8, PR 5). Só para primário do
 * escritório da sessão, com a chave geral do convênio ligada. Nunca lança: o Meu Perfil não cai por causa do bloco.
 */
final class ConvenioNoPerfilExtension extends AbstractExtension
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly ChaveDoConvenio $chave,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('convenio_no_perfil', $this->podeDigitar(...))];
    }

    public function podeDigitar(): bool
    {
        try {
            $vinculo = $this->tenantContext->getCurrentUserTenant();

            return $vinculo !== null && $this->regraDosPrimarios->ehPrimario($vinculo) && $this->chave->ativo();
        } catch (\Throwable $falha) {
            $this->logger->warning('Convênios: bloco do código no Meu Perfil não montou', ['erro' => $falha->getMessage()]);

            return false;
        }
    }
}
