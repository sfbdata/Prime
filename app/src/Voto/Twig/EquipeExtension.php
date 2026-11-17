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
 *    de todas as assembleias do escritório saem de UMA consulta de equipes por requisição (sem N+1 nos cartões);
 *  - `voto_ve_relacao(id)`: a pessoa vê a relação nominal do voto aberto (mesma regra da relação de votantes);
 *  - `voto_decide_avaliacao(id)`: a pessoa decide a avaliação de aptidão e vê a aba "Avaliações" (spec
 *    voto-avaliacoes-do-conselho-1611, seção 3: o conselho e a Administração; sem equipe própria, só a Administração). É a
 *    única escrita do nível Visualizar; os botões e a aba só aparecem para quem não levaria 403. O registro da ocorrência
 *    usa `voto_edita(id)` (a comissão e a Administração, ou quem tem o módulo sem equipe).
 */
final class EquipeExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<int, NivelNaAssembleia>|null por id de assembleia, da pessoa e do escritório da sessão */
    private ?array $niveis = null;

    private ?string $chave = null;

    /** @var array<int, bool>|null a relação de votantes, por id de assembleia, da pessoa e do escritório da sessão */
    private ?array $relacao = null;

    private ?string $chaveRelacao = null;

    /** @var array<int, bool>|null a decisão da avaliação, por id de assembleia, da pessoa e do escritório da sessão */
    private ?array $avaliacao = null;

    private ?string $chaveAvaliacao = null;

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
            new TwigFunction('voto_ve_relacao', $this->veRelacao(...)),
            new TwigFunction('voto_decide_avaliacao', $this->decideAvaliacao(...)),
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

    /**
     * A pessoa vê a relação de votantes e a relação nominal do voto aberto desta assembleia? (comissão, auditoria e
     * fiscal quando há equipe própria; o conselho não). Só esconde; os dados nem chegam à ata sem a regra.
     */
    public function veRelacao(int $assembleiaId): bool
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || null === $tenant) {
            return false;
        }
        $chave = $usuario->getId() . ':' . $tenant->getId();
        if ($this->chaveRelacao !== $chave || null === $this->relacao) {
            $this->relacao = $this->acesso->relacaoEmLote($usuario, $this->assembleias->listarDoEscritorio($tenant));
            $this->chaveRelacao = $chave;
        }

        return $this->relacao[$assembleiaId] ?? false;
    }

    /** A pessoa decide a avaliação de aptidão (e vê a aba "Avaliações") desta assembleia? Só esconde; o UseCase confere o papel. */
    public function decideAvaliacao(int $assembleiaId): bool
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || null === $tenant) {
            return false;
        }
        $chave = $usuario->getId() . ':' . $tenant->getId();
        if ($this->chaveAvaliacao !== $chave || null === $this->avaliacao) {
            $this->avaliacao = $this->acesso->avaliacaoEmLote($usuario, $this->assembleias->listarDoEscritorio($tenant));
            $this->chaveAvaliacao = $chave;
        }

        return $this->avaliacao[$assembleiaId] ?? false;
    }

    public function reset(): void
    {
        $this->avaliacao = null;
        $this->chaveAvaliacao = null;
        $this->relacao = null;
        $this->chaveRelacao = null;
        $this->niveis = null;
        $this->chave = null;
    }
}
