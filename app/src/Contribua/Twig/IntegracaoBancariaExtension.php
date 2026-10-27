<?php

declare(strict_types=1);

namespace App\Contribua\Twig;

use App\Contribua\DTO\IntegracaoBancariaView;
use App\Contribua\UseCase\PremioDoApoiadorUseCase;
use App\Contribua\UseCase\VerIntegracaoBancariaUseCase;
use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Dados dos parciais da C4 na página Contribua (os pontos de inclusão são da f6; a página só passa `p`):
 * a integração bancária (só Master) e o prêmio do apoiador pendente da conta.
 */
final class IntegracaoBancariaExtension extends AbstractExtension
{
    public function __construct(
        private readonly VerIntegracaoBancariaUseCase $ver,
        private readonly PremioDoApoiadorUseCase $premio,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('contribua_integracao_bancaria', $this->integracao(...)),
            new TwigFunction('contribua_premio_pendente', $this->premioPendente(...)),
        ];
    }

    public function integracao(): ?IntegracaoBancariaView
    {
        return $this->security->isGranted('ROLE_SUPER_ADMIN') ? $this->ver->executar() : null;
    }

    /** @return array{id: int, valor: string, nome: string}|null */
    public function premioPendente(): ?array
    {
        $escritorio = $this->tenantContext->getCurrentTenant();
        $usuario = $this->security->getUser();
        if (null === $escritorio || !$usuario instanceof User) {
            return null;
        }
        $pendente = $this->premio->pendente((int) $escritorio->getId());
        $nome = explode(' ', trim((string) $usuario->getFullName()))[0] ?: 'colega';

        return null === $pendente ? null : $pendente + ['nome' => $nome];
    }
}
