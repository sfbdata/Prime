<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Enum\TipoDeCompromisso;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\CalendarioPelaCor;
use App\Repository\LegendaCorRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * História: na lateral da Agenda, ao lado de cada calendário pessoal (Meus compromissos, Audiências,
 * Reuniões, Prazos), a pessoa vê quantos compromissos dela existem daquele tipo, em qualquer data, como
 * no desenho (`E.filter(tipo).length`), e não só os do período que está na tela. O compromisso da agenda
 * antiga, sem tipo, conta no calendário em que aparece na grade (pela cor), pela mesma regra.
 */
final class ContarMeusCompromissosUseCase
{
    public function __construct(
        private readonly EventoDaAgendaRepository $eventos,
        private readonly LegendaCorRepository $legendas,
    ) {
    }

    /** @return array<string, int> por tipo (meus, aud, reun, prazo, urg, pess, lemb), zero quando não há */
    public function executar(Tenant $tenant, User $eu): array
    {
        $regra = new CalendarioPelaCor();
        $mapa = $regra->mapa($this->legendas->findBy(['tenant' => $tenant]));
        $saida = array_fill_keys(array_map(static fn (TipoDeCompromisso $t): string => $t->value, TipoDeCompromisso::cases()), 0);
        foreach ($this->eventos->contarDoCriadorPorTipoECor($tenant, $eu) as $linha) {
            $saida[$regra->calendario($linha['tipo'], $linha['cor'], $mapa)] += $linha['n'];
        }

        return $saida;
    }
}
