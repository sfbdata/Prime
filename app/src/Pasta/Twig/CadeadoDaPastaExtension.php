<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Entity\Auth\User;
use App\Pasta\DTO\CadeadoNaTelaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\TelaDoCadeado;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Symfony\Contracts\Service\ResetInterface;

/**
 * `cadeado_da_pasta(pasta)`: o cadeado da pasta como a pessoa logada o vê (⋮, modal, faixa de somente
 * visualização, pedidos). Na extensão, e não no controller, para o cabeçalho e o show (parciais
 * compartilhados com outra frente) não precisarem de variável nova. Uma montagem por pasta e requisição.
 */
final class CadeadoDaPastaExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<int, CadeadoNaTelaOutput> */
    private array $montados = [];

    public function __construct(
        private readonly TelaDoCadeado $tela,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('cadeado_da_pasta', $this->cadeadoDaPasta(...))];
    }

    public function cadeadoDaPasta(Pasta $pasta): ?CadeadoNaTelaOutput
    {
        $pessoa = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$pessoa instanceof User || $tenant === null || $pasta->getTenant() !== $tenant || $pasta->getId() === null) {
            return null;
        }

        return $this->montados[(int) $pasta->getId()] ??= $this->tela->montar($pasta, $pessoa, $tenant);
    }

    public function reset(): void
    {
        $this->montados = [];
    }
}
