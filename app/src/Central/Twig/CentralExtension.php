<?php

declare(strict_types=1);

namespace App\Central\Twig;

use App\Central\Entity\Presenca;
use App\Central\Repository\ConfiguracaoDePresencaRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `central_disponivel()` — se o lançador flutuante da Central entra na página: usuário logado com vínculo ATIVO no
 * escritório da sessão (a mesma regra da Central, D3). Roda em TODA página do layout, por isso nunca lança: qualquer
 * falha vira "não mostra", e a página segue (o mesmo cuidado da extensão da IA — HANDOFF §8).
 */
final class CentralExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly AcessoACentral $acesso,
        private readonly ConfiguracaoDePresencaRepository $configuracoes,
        private readonly UserTenantRepository $vinculos,
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('central_disponivel', $this->disponivel(...)),
            new TwigFunction('presenca_ausente_apos_min', $this->ausenteAposMin(...)),
            new TwigFunction('presenca_sou_primario', $this->souPrimario(...)),
        ];
    }

    public function disponivel(): bool
    {
        try {
            $usuario = $this->security->getUser();
            $tenant = $this->tenantContext->getCurrentTenant();

            return $usuario instanceof User && $tenant !== null && $this->acesso->temVinculoAtivo($usuario, $tenant);
        } catch (\Throwable) {
            return false;
        }
    }

    /** O limite de ausência do escritório da sessão (fase 3); 60 sem escolha ou sem escritório. Nunca lança. */
    public function ausenteAposMin(): int
    {
        try {
            $tenant = $this->tenantContext->getCurrentTenant();

            return $tenant === null ? Presenca::INATIVO_APOS_MINUTOS : $this->configuracoes->minutosAusente($tenant);
        } catch (\Throwable) {
            return Presenca::INATIVO_APOS_MINUTOS;
        }
    }

    /** Se quem está logado é o primário do escritório da sessão: só ele vê o seletor do dc L.306. Nunca lança. */
    public function souPrimario(): bool
    {
        try {
            $usuario = $this->security->getUser();
            $tenant = $this->tenantContext->getCurrentTenant();
            $vinculo = $usuario instanceof User && $tenant !== null ? $this->vinculos->findAtivoPorUserETenant($usuario, $tenant) : null;

            return $vinculo !== null && $this->regraDosPrimarios->ehPrimario($vinculo);
        } catch (\Throwable) {
            return false;
        }
    }
}
