<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\EquipeOutput;
use App\Central\DTO\MembroDaEquipeOutput;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\PresencaRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * Painel "Equipe · em aberto" (desenho L5641, decisão do dono em 16/10: visível a todos): cada colega com vínculo
 * ATIVO no escritório, eu incluído, com o que tem em aberto (Central + sino, neste escritório) e a presença. Ordem do
 * desenho: mais em aberto primeiro, depois nome.
 */
final class ListarEquipeDaCentralUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly UserRepository $usuarios,
        private readonly MensagemRepository $mensagens,
        private readonly NotificacaoRepository $notificacoes,
        private readonly PresencaRepository $presencas,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @throws SemAcessoACentralException */
    public function executar(User $eu, ?Tenant $tenant): EquipeOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);

        $pessoas = $this->usuarios->findColaboradoresAtivosPorTenant($tenant);
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $pessoas);
        $cargos = $this->usuarios->findCargoPorColaboradores($tenant);
        $fotos = $this->usuarios->findFotoPorColaboradores($tenant);
        $naCentral = $this->mensagens->naoLidasPorUsuario($tenant, $ids, semIa: true);
        $noSino = $this->notificacoes->contarNaoLidasPorUsuarios($tenant, $ids);
        $presencas = $this->presencas->doEscritorio($tenant, $ids);
        $limite = $this->clock->now()->modify(sprintf('-%d seconds', PresencaRepository::ONLINE_SEGUNDOS));

        $equipe = [];
        $online = 0;
        $total = 0;
        foreach ($pessoas as $pessoa) {
            $id = (int) $pessoa->getId();
            $presenca = $presencas[$id] ?? null;
            $estaOnline = $presenca !== null && $presenca['ultimaAtividade'] > $limite;
            $emAberto = ($naCentral[$id] ?? 0) + ($noSino[$id] ?? 0);
            $online += $estaOnline ? 1 : 0;
            $total += $emAberto;
            $equipe[] = new MembroDaEquipeOutput(
                id: $id,
                nome: (string) $pessoa->getFullName(),
                cargo: $cargos[$id] ?? null,
                foto: ($fotos[$id] ?? null) ?: null,
                eu: $id === (int) $eu->getId(),
                emAberto: $emAberto,
                status: $estaOnline ? $presenca['status'] : 'offline',
                online: $estaOnline,
                desde: $estaOnline ? $presenca['statusDesde']->format(\DATE_ATOM) : null,
                vistoEm: !$estaOnline && $presenca !== null ? $presenca['ultimaAtividade']->format(\DATE_ATOM) : null,
            );
        }

        usort($equipe, static fn (MembroDaEquipeOutput $a, MembroDaEquipeOutput $b): int => ($b->emAberto <=> $a->emAberto)
            ?: strcmp(mb_strtolower($a->nome), mb_strtolower($b->nome)));

        return new EquipeOutput($equipe, $online, $total);
    }
}
