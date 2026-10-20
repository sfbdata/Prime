<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\PresencaOnlineOutput;
use App\Central\Entity\Presenca;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\PresencaRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * Quem está on-line no escritório, a cada batimento (spec `presenca-e-ponto-no-cabecalho.md` §2): o selo do avatar
 * conta e o painel agrupa sem pedir a equipe inteira a cada 20 s. Mesmas regras do Painel da equipe: só colegas com
 * vínculo ATIVO (ex-colega não aparece), on-line pelo último batimento (`PresencaRepository::ONLINE_SEGUNDOS`),
 * tenant explícito.
 */
final class ListarPresencaDoEscritorioUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly UserRepository $usuarios,
        private readonly PresencaRepository $presencas,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<PresencaOnlineOutput>
     *
     * @throws SemAcessoACentralException
     */
    public function executar(User $eu, ?Tenant $tenant): array
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $this->usuarios->findColaboradoresAtivosPorTenant($tenant));
        $agora = $this->clock->now();
        $limite = $agora->modify(sprintf('-%d seconds', PresencaRepository::ONLINE_SEGUNDOS));

        $online = [];
        foreach ($this->presencas->doEscritorio($tenant, $ids) as $id => $presenca) {
            if ($presenca['ultimaAtividade'] > $limite) {
                $online[] = new PresencaOnlineOutput($id, $presenca['status'], $this->semAtividadeMin($presenca, $agora));
            }
        }

        return $online;
    }

    /**
     * O navegador passa a `inativo` quando completa o limite sem atividade; `status_desde` é esse instante (com erro de
     * até um batimento). Daí: minutos desde então mais o limite.
     *
     * @param array{status: string, statusDesde: \DateTimeImmutable} $presenca
     */
    private function semAtividadeMin(array $presenca, \DateTimeImmutable $agora): ?int
    {
        if ($presenca['status'] !== Presenca::INATIVO) {
            return null;
        }

        return intdiv(max(0, $agora->getTimestamp() - $presenca['statusDesde']->getTimestamp()), 60) + Presenca::INATIVO_APOS_MINUTOS;
    }
}
