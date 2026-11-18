<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Entity\Auth\User;
use App\Pasta\Service\EscopoDePastas;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `acesso_restrito()`: se o usuário logado tem acesso restrito no escritório da sessão (spec
 * acesso-restrito-e-compartilhar). O chip do responsável usa para mostrar o aviso do desenho 03 no lugar da
 * confirmação da troca (L8): no restrito, a pasta fica sob a responsabilidade dele. O servidor recusa do mesmo jeito.
 *
 * O chip aparece em cada linha da Lista: a resposta fica guardada por usuário e escritório durante a requisição
 * (`ResetInterface` zera entre requisições).
 */
final class AcessoRestritoExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, bool> */
    private array $respostas = [];

    public function __construct(
        private readonly EscopoDePastas $escopo,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('acesso_restrito', $this->acessoRestrito(...))];
    }

    public function acessoRestrito(): bool
    {
        $usuario = $this->security->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null) {
            return false;
        }
        $chave = $usuario->getId() . '@' . $tenant->getId();

        return $this->respostas[$chave] ??= $this->escopo->ehRestritoNoEscritorio($usuario, $tenant);
    }

    public function reset(): void
    {
        $this->respostas = [];
    }
}
