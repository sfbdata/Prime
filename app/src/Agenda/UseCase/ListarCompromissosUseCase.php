<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\DTO\CompromissoOutput as C;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\OcorrenciasDoEvento;
use App\Agenda\Entity\Evento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Profile\Repository\UserProfileRepository;
use App\Repository\LegendaCorRepository;

/**
 * História: a pessoa abre a Agenda e vê, no período, os compromissos que pode ver (os dela e, na
 * "Equipe do escritório", os dos colegas que não são "Só eu"), os prazos e audiências abertos das
 * pastas do escritório e os aniversários dos colegas. Só leitura.
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
    ) {
    }

    /** @return list<C> */
    public function executar(Tenant $tenant, User $eu, \DateTimeImmutable $de, \DateTimeImmutable $ate, bool $soMinha, bool $verPastas, bool $administra = false): array
    {
        $ateFim = $ate->setTime(23, 59, 59);
        $itens = [
            ...$this->dosEventos($tenant, $eu, $de, $ateFim, $soMinha, $administra, $verPastas),
            ...($verPastas ? $this->dosPrazos($tenant, $de, $ate) : []),
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
            calendario: $evento->getTipo()->value ?? $calPorCor[strtolower($evento->getCor())] ?? C::CAL_MEUS,
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
        );
    }

    /**
     * Cor da legenda => calendário, pelo nome da legenda do escritório (ou pelas cores padrão do
     * formulário antigo quando o escritório não tem legendas).
     *
     * @return array<string, string>
     */
    private function calendarioPorCor(Tenant $tenant): array
    {
        $mapa = [strtolower(Evento::COR_AZUL) => C::CAL_AUD, strtolower(Evento::COR_VERDE) => C::CAL_PRAZO, strtolower(Evento::COR_AMARELO) => C::CAL_REUNIAO];
        $legendas = $this->legendas->findBy(['tenant' => $tenant]);
        if ([] !== $legendas) {
            $mapa = [];
        }
        foreach ($legendas as $legenda) {
            $nome = mb_strtolower((string) $legenda->getNome());
            $cal = match (true) {
                str_contains($nome, 'audi')  => C::CAL_AUD,
                str_contains($nome, 'reuni') => C::CAL_REUNIAO,
                str_contains($nome, 'prazo') => C::CAL_PRAZO,
                default                      => null,
            };
            if (null !== $cal) {
                $mapa[strtolower((string) $legenda->getCor())] ??= $cal;
            }
        }

        return $mapa;
    }

    /** @return list<C> */
    private function dosPrazos(Tenant $tenant, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        return array_map(static function (PastaPrazo $z): C {
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
                pastaId: $z->getPasta()->getId(),
                pastaNup: $nup,
            );
        }, $this->prazos->abertosDoEscritorioNoPeriodo($tenant, $de, $ate));
    }

    /** @return list<C> */
    private function dosAniversarios(Tenant $tenant, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $ini = $de->format('Y-m-d');
        $fim = $ate->format('Y-m-d');
        $saida = [];
        foreach ($this->perfis->aniversariantesDoEscritorio($tenant) as $p) {
            for ($ano = (int) $de->format('Y'); $ano <= (int) $ate->format('Y'); ++$ano) {
                $md = $p['nascimento']->format('m-d');
                $data = $ano . '-' . ('02-29' === $md && !checkdate(2, 29, $ano) ? '02-28' : $md);
                if ($data >= $ini && $data <= $fim) {
                    $curto = implode(' ', array_slice(explode(' ', trim($p['nome'])), 0, 2));
                    $saida[] = new C('aniv-' . $p['id'] . '-' . $data, $data, '🎂 Aniversário de ' . $curto, C::CAL_ANIV, C::ORIGEM_ANIV, true, usuarioId: $p['id'], donoNome: $p['nome']);
                }
            }
        }

        return $saida;
    }
}
