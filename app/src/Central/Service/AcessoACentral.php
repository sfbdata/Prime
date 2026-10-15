<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Entity\Conversa;
use App\Central\Entity\Participante;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ParticipanteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;

/**
 * As duas guardas da Central (`docs/specs/central-comunicacao-a1.md` §2), num lugar só:
 *
 * 1. **Usar a Central** = vínculo ATIVO com o escritório da sessão — o mesmo critério de quem é colaborador.
 *    Sem permissão de módulo nova (D3).
 * 2. **Ler ou escrever numa conversa** = ser participante dela. É posse direta, sem `PermissionChecker`: o
 *    checker tem bypass de super admin e de papel de sistema, e aqui ninguém lê conversa alheia.
 */
final class AcessoACentral
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly ParticipanteRepository $participantes,
        private readonly UserRepository $usuarios,
    ) {
    }

    /** @throws SemAcessoACentralException */
    public function exigirVinculoAtivo(User $usuario, ?Tenant $tenant): Tenant
    {
        if ($tenant === null || !$this->vinculos->existeVinculoAtivo($usuario, $tenant)) {
            throw new SemAcessoACentralException();
        }

        return $tenant;
    }

    /** @throws ConversaNaoEncontradaException */
    public function participacao(int $conversaId, User $usuario, Tenant $tenant): Participante
    {
        return $this->participantes->daConversa($conversaId, $usuario, $tenant)
            ?? throw new ConversaNaoEncontradaException($conversaId);
    }

    /**
     * Ids de quem tem vínculo ativo no escritório, numa consulta — para a lista de conversas não perguntar uma
     * vez por conversa.
     *
     * @return array<int, true>
     */
    public function idsComVinculoAtivo(Tenant $tenant): array
    {
        return array_map(static fn (): bool => true, $this->colaboradoresAtivos($tenant));
    }

    /**
     * Quem tem vínculo ativo no escritório, por id — o universo de quem pode entrar num grupo (B).
     *
     * @return array<int, User>
     */
    public function colaboradoresAtivos(Tenant $tenant): array
    {
        $porId = [];
        foreach ($this->usuarios->findColaboradoresAtivosPorTenant($tenant) as $colega) {
            $porId[(int) $colega->getId()] = $colega;
        }

        return $porId;
    }

    /**
     * Quem pode ser @mencionado na conversa (A2): participante dela, com vínculo ATIVO no escritório, menos quem
     * escreve. Terceiro do mesmo escritório não entra — mencionar alguém não pode entregar a conversa a ele.
     *
     * @return array<int, User> id → pessoa
     */
    public function mencionaveis(Conversa $conversa, User $eu, Tenant $tenant): array
    {
        $ativos = $this->idsComVinculoAtivo($tenant);
        $mencionaveis = [];
        foreach ($this->participantes->daConversaInteira($conversa, $tenant) as $participante) {
            $pessoa = $participante->getUsuario();
            $id = (int) $pessoa->getId();
            if ($id !== $eu->getId() && isset($ativos[$id])) {
                $mencionaveis[$id] = $pessoa;
            }
        }

        return $mencionaveis;
    }

    /**
     * Quem administra o grupo DE FATO (`docs/specs/central-comunicacao-b.md` §2): o administrador gravado, se ainda
     * participa e tem vínculo ativo; senão, o participante ativo mais antigo com vínculo ativo (sem isso, um
     * administrador que saiu do escritório travaria o grupo para sempre — D3 não tem bypass). `null` = ninguém.
     */
    public function administradorEfetivo(Conversa $grupo, Tenant $tenant): ?User
    {
        if (!$grupo->isGrupo()) {
            return null;
        }

        $ativos = $this->colaboradoresAtivos($tenant);
        $candidatos = array_values(array_filter(
            array_map(static fn (Participante $p): User => $p->getUsuario(), $this->participantes->daConversaInteira($grupo, $tenant)),
            static fn (User $u): bool => isset($ativos[(int) $u->getId()]),
        ));
        foreach ($candidatos as $pessoa) {
            if ($grupo->isAdministradaPor($pessoa)) {
                return $pessoa;
            }
        }

        return $candidatos[0] ?? null;
    }

    public function temVinculoAtivo(User $usuario, Tenant $tenant): bool
    {
        return $this->vinculos->existeVinculoAtivo($usuario, $tenant);
    }
}
