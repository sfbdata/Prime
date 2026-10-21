<?php

declare(strict_types=1);

namespace App\Profile\Twig;

use App\Entity\Auth\User;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Profile\UseCase\ObterPreferenciasDoPerfilUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `perfil_sons()`: a chave "Sons de alerta" do card Preferências do Meu Perfil (desenho "07 - Meu
 * Perfil", VS 18.10.26, `prefs`, chave `som`), já com o padrão do catálogo. Os três lugares que tocam
 * som (lançador da Central, presença no cabeçalho e calendário do Dashboard) põem o valor em
 * `data-perfil-sons` no próprio elemento raiz, e o JS de cada um não toca nada com "0".
 *
 * Função, e não variável do controller, porque dois desses lugares são parciais do layout que
 * aparecem em toda página. Sem usuário logado ou sem escritório na sessão vale o padrão (ligado).
 *
 * `ResetInterface` (tag `kernel.reset` por autoconfiguração): a memória é por requisição (os três
 * parciais da mesma página fazem uma consulta só) e não pode vazar para a seguinte.
 */
final class PreferenciasDoPerfilExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, bool> "usuario:escritorio" => sons ligados */
    private array $memoria = [];

    public function __construct(
        private readonly ObterPreferenciasDoPerfilUseCase $preferencias,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('perfil_sons', $this->perfilSons(...)),
        ];
    }

    public function perfilSons(): bool
    {
        $usuario = $this->security->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if (!$usuario instanceof User || $tenant === null) {
            return true;
        }

        $chave = $usuario->getId() . ':' . $tenant->getId();
        if (!array_key_exists($chave, $this->memoria)) {
            $efetivas              = $this->preferencias->executar($tenant, $usuario);
            $this->memoria[$chave] = $efetivas[CatalogoDePreferenciasDoPerfil::SONS];
        }

        return $this->memoria[$chave];
    }

    public function reset(): void
    {
        $this->memoria = [];
    }
}
