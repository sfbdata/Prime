<?php

declare(strict_types=1);

namespace App\Dashboard\Twig;

use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard as Catalogo;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `animacoes_reduzidas()`: a opção "Animações" do Guia visual vale para o sistema inteiro, não só
 * para o Dashboard. O `base.html.twig` põe a classe `jp-sem-anim` no `<html>` quando o usuário
 * escolheu "reduzidas" neste escritório, e o app.css desliga transições e animações por ela.
 *
 * Sem usuário ou sem escritório na sessão vale o padrão do catálogo (ligadas). Uma consulta por
 * requisição: o layout pergunta uma vez só.
 */
final class AnimacoesDoUsuarioExtension extends AbstractExtension
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $repository,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('animacoes_reduzidas', $this->animacoesReduzidas(...)),
        ];
    }

    public function animacoesReduzidas(): bool
    {
        $usuario = $this->security->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null) {
            return false;
        }

        $valores = $this->repository->valoresDoUsuario($tenant, $usuario, [Catalogo::ANIMACOES]);

        return ($valores[Catalogo::ANIMACOES] ?? null) === Catalogo::ANIMACOES_REDUZIDAS;
    }
}
