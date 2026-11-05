<?php

declare(strict_types=1);

namespace App\Central\Twig;

use App\Central\Entity\Presenca;
use App\Central\Repository\ConfiguracaoDePresencaRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `central_disponivel()` — se o lançador flutuante da Central entra na página: usuário logado com vínculo ATIVO no
 * escritório da sessão (a mesma regra da Central, D3). Roda em TODA página do layout, por isso nunca lança: qualquer
 * falha vira "não mostra", e a página segue (o mesmo cuidado da extensão da IA — HANDOFF §8).
 *
 * Memória por requisição (spec memoria-do-layout): o layout e os parciais da pasta perguntam `central_disponivel()`
 * várias vezes; o vínculo de quem está logado é lido uma vez por pessoa e escritório e serve às duas funções que
 * dependem dele. `ResetInterface` (tag `kernel.reset`) zera entre requisições e entre mensagens do worker.
 */
final class CentralExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, UserTenant> por "usuario:escritorio"; só o vínculo achado (o "sem vínculo" não é guardado) */
    private array $vinculosAtivos = [];

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
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

            return $usuario instanceof User && $tenant !== null && $this->vinculoAtivo($usuario, $tenant) !== null;
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
            $vinculo = $usuario instanceof User && $tenant !== null ? $this->vinculoAtivo($usuario, $tenant) : null;

            return $vinculo !== null && $this->regraDosPrimarios->ehPrimario($vinculo);
        } catch (\Throwable) {
            return false;
        }
    }

    public function reset(): void
    {
        $this->vinculosAtivos = [];
    }

    /**
     * O vínculo ATIVO de quem está logado no escritório da sessão, uma consulta por pessoa e escritório. Vale pelo
     * `AcessoACentral::temVinculoAtivo()` de antes (a contagem de vínculos ativos): o par usuário e escritório é único
     * (`uq_user_tenant`), então "existe" e "achou um" são a mesma resposta. O "sem vínculo" não fica guardado: um
     * vínculo criado ou reativado na mesma requisição aparece na pergunta seguinte, como antes.
     */
    private function vinculoAtivo(User $usuario, Tenant $tenant): ?UserTenant
    {
        $chave = $usuario->getId() . ':' . $tenant->getId();
        // Vínculo guardado que foi desativado nesta requisição volta ao banco (a resposta de antes era a do banco).
        if (isset($this->vinculosAtivos[$chave]) && $this->vinculosAtivos[$chave]->isActive()) {
            return $this->vinculosAtivos[$chave];
        }
        unset($this->vinculosAtivos[$chave]);
        $vinculo = $this->vinculos->findAtivoPorUserETenant($usuario, $tenant);
        if ($vinculo !== null) {
            $this->vinculosAtivos[$chave] = $vinculo;
        }

        return $vinculo;
    }
}
