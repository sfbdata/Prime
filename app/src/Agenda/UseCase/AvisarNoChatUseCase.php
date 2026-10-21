<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Entity\Evento;
use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\AvisoDoCompromisso;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;

/**
 * História: no detalhe de um compromisso que vê, a pessoa clica em "Avisar no Chat" e escolhe
 * colegas. Cada um recebe o resumo e o atalho, e passa a constar em "Avisados no Chat". Quem já tinha
 * sido avisado não recebe de novo. Colegas de outro escritório ou desligados são ignorados. Compromisso
 * "Só eu" não é avisado: ninguém mais conseguiria abri-lo.
 */
final class AvisarNoChatUseCase
{
    public function __construct(
        private readonly EventoDaAgendaRepository $eventos,
        private readonly UserRepository $usuarios,
        private readonly AvisoDoCompromisso $aviso,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return int quantos foram avisados agora
     */
    public function executar(int $id, array $ids, User $eu, Tenant $tenant): int
    {
        $evento = $this->eventos->visivelPara($id, $tenant, $eu) ?? throw new CompromissoNaoEncontradoException();
        if (Evento::VISIBILIDADE_SOMENTE_EU === $evento->getVisibilidade()) {
            throw new \DomainException('Compromisso "Só eu" não pode ser avisado: ninguém mais consegue abri-lo.');
        }
        $ativos = array_values(array_filter($this->usuarios->findColaboradoresAtivosPorTenant($tenant), static fn (User $u): bool => in_array($u->getId(), $ids, true)));
        $novos = $evento->avisar(array_map(static fn (User $u): int => (int) $u->getId(), $ativos));
        $this->eventos->salvar($evento);

        return $this->aviso->avisar($evento, array_filter($ativos, static fn (User $u): bool => in_array($u->getId(), $novos, true)), $eu);
    }
}
