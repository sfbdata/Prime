<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\DTO\CompromissoOutput as C;
use App\Agenda\Repository\AjusteDeDataRepository;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\AniversariosDaEquipe;
use App\Agenda\Service\CalendarioPelaCor;
use App\Agenda\Service\OcorrenciasDoEvento;
use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Profile\Repository\UserProfileRepository;
use App\Repository\LegendaCorRepository;

/**
 * História: a pessoa abre a Agenda e vê, no período, os compromissos que pode ver (os dela e, na
 * "Equipe do escritório", os dos colegas que não são "Só eu"), os prazos e audiências abertos das
 * pastas do escritório (com a permissão "Ver prazos na agenda") e os aniversários dos colegas. Só leitura.
 *
 * O Evento ainda não tem tipo: até a entrega 3, o calendário (Audiência, Reunião, Prazo ou Meus
 * compromissos) sai da legenda de cor escolhida no formulário antigo.
 */
final class ListarCompromissosUseCase
{
    private const MAX_DIAS_DIA_INTEIRO = 62;

    public function __construct(
        private readonly EventoDaAgendaRepository $eventos,
        private readonly OcorrenciasDoEvento $ocorrencias,
        private readonly PastaPrazoRepository $prazos,
        private readonly UserProfileRepository $perfis,
        private readonly LegendaCorRepository $legendas,
        private readonly AjusteDeDataRepository $ajustes,
        // Cadeado (spec pasta-cadeado D3): prazos e audiências de pasta oculta para quem olha não entram.
        private readonly ?AcessoAPasta $acessoAPasta = null,
    ) {
    }

    /** @return list<C> */
    public function executar(Tenant $tenant, User $eu, \DateTimeImmutable $de, \DateTimeImmutable $ate, bool $soMinha, bool $verPastas, bool $verPrazos, bool $administra = false): array
    {
        $ateFim = $ate->setTime(23, 59, 59);
        $itens = [
            ...$this->dosEventos($tenant, $eu, $de, $ateFim, $soMinha, $administra, $verPastas),
            ...($verPrazos ? $this->dosPrazos($tenant, $de, $ate, $this->acessoAPasta?->idsOcultosPara($eu, (int) $tenant->getId()) ?? [], $verPastas) : []),
            ...$this->dosAniversarios($tenant, $de, $ate),
        ];
        usort($itens, static fn (C $a, C $b): int => [$a->data, $a->dia ? '' : $a->h1] <=> [$b->data, $b->dia ? '' : $b->h1]);

        return $itens;
    }

    /** @return list<C> */
    private function dosEventos(Tenant $tenant, User $eu, \DateTimeImmutable $de, \DateTimeImmutable $ate, bool $soMinha, bool $administra, bool $verPastas): array
    {
        $calPorCor = $this->calendarioPorCor($tenant);
        $saida = [];
        foreach ($this->eventos->doEscritorioNoPeriodo($tenant, $eu, $de, $ate, $soMinha) as $evento) {
            foreach ($this->ocorrencias->noPeriodo($evento, $de, $ate) as $o) {
                foreach ($this->diasDaOcorrencia($evento, $o['inicio'], $o['fim'], $de, $ate) as $dia) {
                    $saida[] = $this->compromisso($evento, $eu, $dia, $o['inicio'], $o['fim'], $calPorCor, $administra, $verPastas);
                }
            }
        }

        return $saida;
    }

    /**
     * Evento de dia inteiro que dura vários dias aparece em cada dia do período; o com horário, no
     * dia em que começa. Fim exatamente à meia-noite não abre mais um dia: é o limite exclusivo que o
     * arrastar da agenda antiga (FullCalendar) grava.
     *
     * @return list<string>
     */
    private function diasDaOcorrencia(Evento $evento, \DateTimeImmutable $inicio, \DateTimeImmutable $fim, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        if (!$evento->isDiaInteiro()) {
            return [$inicio->format('Y-m-d')];
        }
        if ('00:00:00' === $fim->format('H:i:s') && $fim > $inicio) {
            $fim = $fim->modify('-1 second');
        }
        $dias = [];
        $dia = max($inicio->setTime(0, 0), $de->setTime(0, 0));
        for ($n = 0; $dia <= $fim && $dia <= $ate && $n < self::MAX_DIAS_DIA_INTEIRO; ++$n, $dia = $dia->modify('+1 day')) {
            $dias[] = $dia->format('Y-m-d');
        }

        return $dias;
    }

    /** @param array<string, string> $calPorCor */
    private function compromisso(Evento $evento, User $eu, string $dia, \DateTimeImmutable $inicio, \DateTimeImmutable $fim, array $calPorCor, bool $administra, bool $verPastas): C
    {
        $id = (int) $evento->getId();
        $obs = trim(implode("\n", array_filter([(string) $evento->getLocal(), (string) $evento->getDescricao()])));

        return new C(
            id: 'ev-' . $id . '-' . $dia,
            data: $dia,
            titulo: $evento->getTitulo(),
            calendario: (new CalendarioPelaCor())->calendario($evento->getTipo(), $evento->getCor(), $calPorCor),
            origem: C::ORIGEM_AGENDA,
            dia: $evento->isDiaInteiro(),
            h1: $evento->isDiaInteiro() ? '' : $inicio->format('H:i'),
            h2: $evento->isDiaInteiro() || $fim->format('Y-m-d') !== $inicio->format('Y-m-d') ? '' : $fim->format('H:i'),
            meu: $evento->getCriador()?->getId() === $eu->getId(),
            donoNome: $evento->getCriador()?->getFullName(),
            privado: Evento::VISIBILIDADE_SOMENTE_EU === $evento->getVisibilidade(),
            observacao: '' === $obs ? null : $obs,
            status: $evento->getStatus(),
            recorrente: $evento->isRecorrente(),
            eventoId: $id,
            pastaId: $verPastas ? $evento->getPasta()?->getId() : null,
            pastaNup: $verPastas ? $evento->getPasta()?->getNup() : null,
            tipo: $evento->getTipo()?->value,
            lembreteMinutos: $evento->getLembreteMinutos(),
            avisados: $evento->getAvisados(),
            participantes: array_values(array_map(static fn (User $u): int => (int) $u->getId(), $evento->getParticipantes()->toArray())),
            repetir: $evento->isRecorrente() ? $evento->getTipoRecorrencia() : null,
            repetirAte: $evento->getFimRecorrencia()?->format('Y-m-d'),
            editavel: $administra || $evento->getCriador()?->getId() === $eu->getId(),
            inicioOriginal: $evento->getDataInicio()?->format('Y-m-d\TH:i'),
            fimOriginal: $evento->getDataFim()?->format('Y-m-d\TH:i'),
            doGoogle: $evento->isImportadoDoGoogle(),
            local: '' === (string) $evento->getLocal() ? null : (string) $evento->getLocal(),
            descricao: '' === (string) $evento->getDescricao() ? null : (string) $evento->getDescricao(),
        );
    }

    /** @return array<string, string> cor => calendário (regra única com a contagem da lateral) */
    private function calendarioPorCor(Tenant $tenant): array
    {
        return (new CalendarioPelaCor())->mapa($this->legendas->findBy(['tenant' => $tenant]));
    }

    /**
     * @param list<int> $pastasOcultas
     *
     * @return list<C>
     */
    private function dosPrazos(Tenant $tenant, \DateTimeImmutable $de, \DateTimeImmutable $ate, array $pastasOcultas, bool $verPastas): array
    {
        return array_map(static function (PastaPrazo $z) use ($verPastas): C {
            $nup = (string) $z->getPasta()->getNup();
            $hora = (string) $z->getHora();

            return new C(
                id: 'pz-' . $z->getId(),
                data: $z->getData()->format('Y-m-d'),
                titulo: ($z->isAudiencia() ? 'Audiência' : ($z->getTitulo() ?: 'Prazo')) . ' · Pasta ' . $nup,
                calendario: $z->isAudiencia() ? C::CAL_AUD : C::CAL_PRAZO,
                origem: C::ORIGEM_PASTA,
                dia: '' === $hora,
                h1: $hora,
                observacao: null !== $z->getResponsavel() && '' !== $z->getResponsavel() ? 'Responsável: ' . $z->getResponsavel() : null,
                status: $z->estaCumprido() ? 'concluido' : null,
                // Sem o módulo Pastas, sem o atalho "Abrir a Pasta" (daria 403).
                pastaId: $verPastas ? $z->getPasta()->getId() : null,
                pastaNup: $nup,
            );
        }, $this->prazos->doEscritorioNoPeriodo($tenant, $de, $ate, $pastasOcultas));
    }

    /** @return list<C> os aniversários da equipe no período (regra única com o envio ao Google) */
    private function dosAniversarios(Tenant $tenant, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        return (new AniversariosDaEquipe($this->perfis, $this->ajustes))->noPeriodo($tenant, $de, $ate);
    }
}
