<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Entity\EventoDeDesempenho;
use App\Master\Entity\IncidenteDeDesempenho;
use App\Master\Entity\RegistroDeBackup;
use App\Master\Repository\EventoDeDesempenhoRepository;
use App\Master\Repository\IncidenteDeDesempenhoRepository;
use App\Master\Repository\RegistroDeBackupRepository;
use App\Master\Service\ApuracaoDeDesempenho;
use Psr\Clock\ClockInterface;

/**
 * Dados da seção "Desempenho" do Painel do Master (desenho 05 Perfis 1.2 VS 25.10; spec
 * docs/specs/master-desempenho.md). Só leitura: indicadores do período, erros recentes, incidentes e
 * backups. As integrações e o formato do texto ficam com a tela (PR 3).
 */
final class ConsultarDesempenhoUseCase
{
    public const PERIODOS_EM_DIAS = [1, 7, 30];
    public const PERIODO_PADRAO_EM_DIAS = 7;
    private const ERROS_RECENTES = 12;
    private const INCIDENTES_LISTADOS = 20;
    private const BACKUPS_LISTADOS = 8;

    public function __construct(
        private readonly EventoDeDesempenhoRepository $eventos,
        private readonly IncidenteDeDesempenhoRepository $incidentes,
        private readonly RegistroDeBackupRepository $backups,
        private readonly ApuracaoDeDesempenho $apuracao,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{periodo: int, indicadores: array<string, array<string, mixed>>, erros: list<array<string, mixed>>, incidentes: list<array<string, mixed>>, incidentesAbertos: int, backups: list<array<string, mixed>>}
     */
    public function executar(int $periodoEmDias): array
    {
        $periodo = in_array($periodoEmDias, self::PERIODOS_EM_DIAS, true) ? $periodoEmDias : self::PERIODO_PADRAO_EM_DIAS;
        $agora = \DateTimeImmutable::createFromInterface($this->clock->now());
        $desde = $agora->modify("-{$periodo} days");

        return [
            'periodo'     => $periodo,
            'indicadores' => $this->apuracao->indicadores(
                $this->eventos->resumoDesde($desde),
                $this->backups->ultimoBackupConcluido(),
                $this->backups->ultimaRestauracao(),
                $agora,
            ),
            'erros'             => array_map($this->erro(...), $this->eventos->errosRecentesDesde($desde, self::ERROS_RECENTES)),
            'incidentes'        => array_map($this->incidente(...), $this->incidentes->recentes(self::INCIDENTES_LISTADOS)),
            'incidentesAbertos' => $this->incidentes->contarAbertos(),
            'backups'           => array_map($this->backup(...), $this->backups->recentes(self::BACKUPS_LISTADOS)),
        ];
    }

    /** @return array<string, mixed> */
    private function erro(EventoDeDesempenho $e): array
    {
        return ['em' => $e->getEm()->format(\DATE_ATOM), 'pagina' => $e->getPagina(), 'mensagem' => $e->getMensagem(), 'origem' => $e->getOrigem()];
    }

    /** @return array<string, mixed> */
    private function incidente(IncidenteDeDesempenho $i): array
    {
        return [
            'id'          => $i->getId(),
            'titulo'      => $i->getTitulo(),
            'categoria'   => $i->getCategoria(),
            'gravidade'   => $i->getGravidade()->value,
            'abertoEm'    => $i->getAbertoEm()->format(\DATE_ATOM),
            'resolvidoEm' => $i->getResolvidoEm()?->format(\DATE_ATOM),
            'aberto'      => $i->estaAberto(),
        ];
    }

    /** @return array<string, mixed> */
    private function backup(RegistroDeBackup $b): array
    {
        return [
            'tipo'       => $b->getTipo()->value,
            'ok'         => $b->isOk(),
            'em'         => $b->getRegistradoEm()->format(\DATE_ATOM),
            'observacao' => $b->getObservacao(),
        ];
    }
}
