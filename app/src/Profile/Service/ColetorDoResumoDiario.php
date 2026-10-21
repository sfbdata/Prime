<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Agenda\Entity\Evento;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\OcorrenciasDoEvento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Profile\DTO\ItensDoResumoDiario;
use App\Tarefa\Repository\TarefaRepository;

/**
 * Junta o que vai no resumo diário de uma pessoa num escritório (decisões do coordenador, 21/10/2026; spec
 * docs/specs/resumo-diario-por-email.md):
 * - prazos e audiências abertos do dia nas pastas de que ela é a responsável (o responsável do prazo é texto livre;
 *   o da pasta é a pessoa);
 * - compromissos de hoje do tipo Prazo ou Audiência na agenda dela (que criou ou de que participa), ainda agendados;
 * - metas abertas que vencem hoje em que ela é responsável.
 * Pasta oculta para ela pelo cadeado não entra (nem o prazo, nem o compromisso ligado, nem a meta).
 */
final class ColetorDoResumoDiario
{
    private const TIPOS_DA_AGENDA = [TipoDeCompromisso::Prazo, TipoDeCompromisso::Audiencia];

    public function __construct(
        private readonly PastaPrazoRepository $prazos,
        private readonly EventoDaAgendaRepository $eventos,
        private readonly OcorrenciasDoEvento $ocorrencias,
        private readonly TarefaRepository $tarefas,
        private readonly AcessoAPasta $acessoAPasta,
    ) {
    }

    public function para(Tenant $tenant, User $pessoa, \DateTimeImmutable $hoje): ItensDoResumoDiario
    {
        $dia = $hoje->setTime(0, 0);
        $ocultas = $this->acessoAPasta->idsOcultosPara($pessoa, (int) $tenant->getId());

        return new ItensDoResumoDiario(
            [...$this->prazosDasPastas($tenant, $pessoa, $dia, $ocultas), ...$this->daAgenda($tenant, $pessoa, $dia, $ocultas)],
            array_map(static fn ($t): array => ['titulo' => $t->getTitulo(), 'pasta' => self::rotuloDaPasta($t->getPasta())], $this->tarefas->abertasDoResponsavelNoDia($tenant, $pessoa, $dia, $ocultas)),
        );
    }

    /**
     * @param list<int> $ocultas
     *
     * @return list<array{titulo: string, tipo: string, hora: ?string, pasta: ?string}>
     */
    private function prazosDasPastas(Tenant $tenant, User $pessoa, \DateTimeImmutable $dia, array $ocultas): array
    {
        $saida = [];
        foreach ($this->prazos->abertosDoEscritorioNoPeriodo($tenant, $dia, $dia, $ocultas) as $z) {
            if ($z->getPasta()->getResponsavel()?->getId() !== $pessoa->getId()) {
                continue;
            }
            $hora = $z->getHora();
            $saida[] = [
                'titulo' => $z->getTitulo(),
                'tipo'   => $z->getTipo() === PastaPrazo::TIPO_AUDIENCIA ? 'Audiência' : 'Prazo',
                'hora'   => $hora === null || $hora === '' ? null : $hora,
                'pasta'  => self::rotuloDaPasta($z->getPasta()),
            ];
        }

        return $saida;
    }

    /**
     * @param list<int> $ocultas
     *
     * @return list<array{titulo: string, tipo: string, hora: ?string, pasta: ?string}>
     */
    private function daAgenda(Tenant $tenant, User $pessoa, \DateTimeImmutable $dia, array $ocultas): array
    {
        $fim = $dia->setTime(23, 59, 59);
        $saida = [];
        foreach ($this->eventos->doEscritorioNoPeriodo($tenant, $pessoa, $dia, $fim, true) as $evento) {
            if (!$this->entraNoResumo($evento, $ocultas)) {
                continue;
            }
            foreach ($this->ocorrencias->noPeriodo($evento, $dia, $fim) as $o) {
                $saida[] = [
                    'titulo' => $evento->getTitulo(),
                    'tipo'   => $evento->getTipo() === TipoDeCompromisso::Audiencia ? 'Audiência' : 'Prazo',
                    'hora'   => $evento->isDiaInteiro() ? null : $o['inicio']->format('H:i'),
                    'pasta'  => self::rotuloDaPasta($evento->getPasta()),
                ];
            }
        }

        return $saida;
    }

    /** @param list<int> $ocultas */
    private function entraNoResumo(Evento $evento, array $ocultas): bool
    {
        $pasta = $evento->getPasta();

        return in_array($evento->getTipo(), self::TIPOS_DA_AGENDA, true)
            && $evento->getStatus() === Evento::STATUS_AGENDADO
            && ($pasta === null || !in_array((int) $pasta->getId(), $ocultas, true));
    }

    private static function rotuloDaPasta(?Pasta $pasta): ?string
    {
        if ($pasta === null) {
            return null;
        }
        $nup = trim((string) $pasta->getNup());

        return $nup !== '' ? $nup : '#' . $pasta->getId();
    }
}
