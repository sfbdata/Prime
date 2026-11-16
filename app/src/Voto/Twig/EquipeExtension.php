<?php

declare(strict_types=1);

namespace App\Voto\Twig;

use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use App\Voto\Enum\NivelNaAssembleia;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\AcessoAAssembleia;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig do teto da equipe (spec docs/specs/voto-equipe-e-master-1611.md). Só ESCONDE atalhos: a decisão de verdade é da
 * `BarreiraDaEquipeListener` e dos UseCases.
 *
 *  - `voto_gere_equipe()`: a pessoa logada é a Administração do escritório da sessão (Master ou primário ativo)? Só ela
 *    vê a entrada "Perfis" (o desenho a mostra só para o perfil `admin`);
 *  - `voto_edita(id)`: a pessoa EDITA esta assembleia? Falso para quem a equipe própria deixa em `Visualizar` (auditoria,
 *    fiscal, conselho): os botões e formulários de escrita, que dariam 403, não aparecem. O ponto único é este; os níveis
 *    de todas as assembleias do escritório saem de UMA consulta de equipes por requisição (sem N+1 nos cartões).
 */
final class EquipeExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<int, NivelNaAssembleia>|null por id de assembleia, da pessoa e do escritório da sessão */
    private ?array $niveis = null;

    private ?string $chave = null;

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly AcessoAAssembleia $acesso,
        private readonly AssembleiaRepository $assembleias,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('voto_gere_equipe', $this->gereEquipe(...)),
            new TwigFunction('voto_edita', $this->edita(...)),
        ];
    }

    public function gereEquipe(): bool
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        return $usuario instanceof User && null !== $tenant && $this->acesso->ehAdministracao($usuario, $tenant);
    }

    public function edita(int $assembleiaId): bool
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || null === $tenant) {
            return true;
        }
        $chave = $usuario->getId() . ':' . $tenant->getId();
        if ($this->chave !== $chave || null === $this->niveis) {
            $this->niveis = $this->acesso->niveisEmLote($usuario, $this->assembleias->listarDoEscritorio($tenant));
            $this->chave = $chave;
        }

        return NivelNaAssembleia::Editar === ($this->niveis[$assembleiaId] ?? NivelNaAssembleia::Editar);
    }

    public function reset(): void
    {
        $this->niveis = null;
        $this->chave = null;
    }
}
