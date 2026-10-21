<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\DTO\LembreteOutput as L;
use App\Agenda\Entity\Evento;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\OcorrenciasDoEvento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Service\AcessoAPasta;

/**
 * História: em qualquer tela do sistema, o navegador pergunta de tempos em tempos quais lembretes a
 * pessoa tem nas próximas horas e mostra o cartão com som no horário (desenho 09, bj-lembretes.js).
 * Entram os compromissos dela (que criou ou de que participa), com horário, não cancelados nem
 * concluídos, com o lembrete escolhido (ou o padrão do tipo; "Sem lembrete" fica de fora), e, para quem
 * vê pastas, os prazos e audiências abertos de hoje com hora marcada (padrão: audiência 60 min, prazo 15).
 */
final class ListarLembretesUseCase
{
    private const JANELA_HORAS = 24;
    /**
     * O navegador mostra o cartão até 2 h depois do início, na hora local dele. O servidor folga a busca
     * para trás (5 h) para cobrir quem está a oeste de Brasília (até UTC-5); o corte de 2 h é do navegador.
     */
    private const ATRASO_HORAS = 5;
    /**
     * Hora "de parede", sem fuso: é a hora que a Agenda mostra (H:i do compromisso), e o navegador a lê
     * no fuso dele, como o bj-lembretes.js do desenho (new Date(dia + 'T' + hora)).
     */
    private const PAREDE = 'Y-m-d\TH:i:s';

    public function __construct(
        private readonly EventoDaAgendaRepository $eventos,
        private readonly OcorrenciasDoEvento $ocorrencias,
        private readonly PastaPrazoRepository $prazos,
        // Cadeado (spec pasta-cadeado D3): prazo de pasta oculta para a pessoa não vira lembrete dela.
        private readonly ?AcessoAPasta $acessoAPasta = null,
    ) {
    }

    /** @return list<L> */
    public function executar(Tenant $tenant, User $eu, \DateTimeImmutable $agora, bool $verPastas, bool $verPrazos): array
    {
        $de = $agora->modify('-' . self::ATRASO_HORAS . ' hours');
        $ate = $agora->modify('+' . (self::JANELA_HORAS + 1) . ' hours');
        $lembretes = [...$this->dosCompromissos($tenant, $eu, $de, $ate, $agora, $verPastas), ...($verPrazos ? $this->dosPrazosDeHoje($tenant, $eu, $agora, $verPastas) : [])];
        usort($lembretes, static fn (L $a, L $b): int => $a->aviso <=> $b->aviso);

        return $lembretes;
    }

    /** @return list<L> */
    private function dosCompromissos(Tenant $tenant, User $eu, \DateTimeImmutable $de, \DateTimeImmutable $ate, \DateTimeImmutable $agora, bool $verPastas): array
    {
        $saida = [];
        foreach ($this->eventos->doEscritorioNoPeriodo($tenant, $eu, $de, $ate, true) as $evento) {
            $minutos = $this->minutosAntes($evento);
            if ($evento->isDiaInteiro() || null === $minutos || Evento::STATUS_AGENDADO !== $evento->getStatus()) {
                continue;
            }
            foreach ($this->ocorrencias->noPeriodo($evento, $de, $ate) as $o) {
                $aviso = $o['inicio']->modify('-' . $minutos . ' minutes');
                if ($aviso <= $agora->modify('+' . self::JANELA_HORAS . ' hours') && $o['inicio'] >= $de) {
                    $saida[] = $this->doEvento($evento, $o['inicio'], $aviso, $verPastas);
                }
            }
        }

        return $saida;
    }

    /** Minutos antes do início; nulo quando a pessoa escolheu "Sem lembrete". */
    private function minutosAntes(Evento $evento): ?int
    {
        $escolhido = $evento->getLembreteMinutos();
        if (0 === $escolhido) {
            return null;
        }

        return $escolhido ?? ($evento->getTipo() ?? TipoDeCompromisso::Meus)->lembretePadrao();
    }

    private function doEvento(Evento $evento, \DateTimeImmutable $inicio, \DateTimeImmutable $aviso, bool $verPastas): L
    {
        $obs = trim((string) $evento->getDescricao());

        return new L(
            k: 'ag:' . $evento->getId() . ':' . $inicio->format('Y-m-d\TH:i'),
            titulo: $evento->getTitulo(),
            quando: $inicio->format(self::PAREDE),
            aviso: $aviso->format(self::PAREDE),
            tipo: ($evento->getTipo() ?? TipoDeCompromisso::Meus)->value,
            observacao: '' === $obs ? null : mb_substr($obs, 0, 200),
            eventoId: $evento->getId(),
            pastaId: $verPastas ? $evento->getPasta()?->getId() : null,
            dia: $inicio->format('Y-m-d'),
        );
    }

    /** @return list<L> */
    private function dosPrazosDeHoje(Tenant $tenant, User $eu, \DateTimeImmutable $agora, bool $verPastas): array
    {
        $hoje = $agora->setTime(0, 0);
        $ocultas = $this->acessoAPasta?->idsOcultosPara($eu, (int) $tenant->getId()) ?? [];
        $saida = [];
        foreach ($this->prazos->abertosDoEscritorioNoPeriodo($tenant, $hoje, $hoje, $ocultas) as $z) {
            $hora = $z->getHora();
            if (null === $hora || '' === $hora) {
                continue;
            }
            $saida[] = $this->doPrazo($z, new \DateTimeImmutable($hoje->format('Y-m-d') . ' ' . $hora, $agora->getTimezone()), $verPastas);
        }

        return $saida;
    }

    private function doPrazo(PastaPrazo $z, \DateTimeImmutable $inicio, bool $verPastas): L
    {
        $aud = $z->isAudiencia();
        $nup = (string) $z->getPasta()->getNup();
        $resp = $z->getResponsavel();

        return new L(
            k: 'pz:' . $z->getId() . ':' . $inicio->format('Y-m-d'),
            titulo: ($aud ? 'Audiência' : ($z->getTitulo() ?: 'Prazo')) . ' · Pasta ' . $nup,
            quando: $inicio->format(self::PAREDE),
            aviso: $inicio->modify('-' . ($aud ? 60 : 15) . ' minutes')->format(self::PAREDE),
            tipo: $aud ? 'aud' : 'prazo',
            observacao: null !== $resp && '' !== $resp ? 'Responsável: ' . $resp : null,
            eventoId: null,
            // Sem o módulo Pastas, sem o atalho "Abrir pasta" (daria 403).
            pastaId: $verPastas ? $z->getPasta()->getId() : null,
            dia: $inicio->format('Y-m-d'),
        );
    }
}
