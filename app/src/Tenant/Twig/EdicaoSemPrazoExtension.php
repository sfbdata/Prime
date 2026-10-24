<?php

declare(strict_types=1);

namespace App\Tenant\Twig;

use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\PoderDeEdicaoSemPrazo;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * A tela pergunta ao MESMO {@see PoderDeEdicaoSemPrazo} que os UseCases (spec `edicao-sem-prazo-master-primario.md` §4),
 * para quem está logado no escritório da sessão. O template passa a regra de sempre (autor e dentro do prazo) e recebe:
 *
 * - `sem_prazo_libera(regra)`: a regra OU o poder (mostra o botão);
 * - `sem_prazo_selo(regra)`: 'master' | 'primario' quando foi o poder que liberou (o selo do §5); senão null.
 */
final class EdicaoSemPrazoExtension extends AbstractExtension
{
    public function __construct(
        private readonly PoderDeEdicaoSemPrazo $poder,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sem_prazo_libera', $this->libera(...)),
            new TwigFunction('sem_prazo_selo', $this->selo(...)),
        ];
    }

    public function libera(bool $pelaRegraDeSempre): bool
    {
        if ($pelaRegraDeSempre) {
            return true;
        }

        return $this->selo(false) !== null;
    }

    public function selo(bool $pelaRegraDeSempre): ?string
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($pelaRegraDeSempre || !$usuario instanceof User || $tenant === null) {
            return null;
        }

        return $this->poder->selo($usuario, $tenant, false)?->value;
    }
}
