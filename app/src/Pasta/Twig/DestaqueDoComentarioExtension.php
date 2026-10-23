<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaComentarioDestaque;
use App\Pasta\Repository\PastaComentarioDestaqueRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Os destaques dos comentários da pasta para a tela (spec pasta-destacar-comentario.md): aba => [comentário => cor].
 * Só a tela da pasta chama, e ela já exige ver a pasta (tenant e cadeado); a pasta de outro escritório vem vazia, e a
 * aba oculta para quem vê a tela fica de fora (como o resto dela). A paleta vai junto, para o JS não ter outra cópia.
 */
final class DestaqueDoComentarioExtension extends AbstractExtension
{
    public function __construct(
        private readonly PastaComentarioDestaqueRepository $destaques,
        private readonly TenantContext $tenantContext,
        private readonly VisibilidadeDasAbasDaPasta $visibilidade,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('destaques_dos_comentarios', $this->destaquesDosComentarios(...)),
            new TwigFunction('cores_de_destaque', static fn (): array => PastaComentarioDestaque::CORES),
        ];
    }

    /** @return array<string, array<int, string>> */
    public function destaquesDosComentarios(Pasta $pasta): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $usuario = $this->security->getUser();
        if ($tenant === null || !$usuario instanceof User || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            return [];
        }
        $mapa = $this->destaques->mapaDaPasta($pasta, $tenant);
        foreach ($this->visibilidade->abasOcultasPara($pasta, $usuario) as $oculta) {
            unset($mapa[$oculta->value]);
        }

        return $mapa;
    }
}
