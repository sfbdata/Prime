<?php

declare(strict_types=1);

namespace App\Tenant\Twig;

use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\GuardaDaExclusaoPeloPrimario;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `exclusao_da_conta()`: o estado do card "Excluir conta do escritório" do Meu Perfil (PR 5). Nulo quando
 * o card não aparece (sem escritório, quem não é primário, conta do Master). Com pedido pendente, a data e
 * o motivo do texto do desenho.
 */
final class ExclusaoDaContaExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly GuardaDaExclusaoPeloPrimario $guarda,
        private readonly PedidoDeExclusaoDeContaRepository $pedidos,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('exclusao_da_conta', [$this, 'estado'])];
    }

    /** @return array{pendente: bool, criadoEm: ?\DateTimeImmutable, motivo: ?string, email: string}|null */
    public function estado(): ?array
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null || !$this->guarda->podeVer($usuario, $tenant)) {
            return null;
        }
        $pedido = $this->pedidos->pendenteDoEscritorio($tenant);

        return [
            'pendente' => $pedido !== null,
            'criadoEm' => $pedido?->getCriadoEm(),
            'motivo' => $pedido?->getMotivo(),
            'email' => (string) $usuario->getEmail(),
        ];
    }
}
