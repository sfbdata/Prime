<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Repository\RegistroPontoRepository;

/**
 * O aviso de ponto da pessoa agora (modal global, consulta a cada 60 s em /ponto/alerta-horario).
 *
 * Feriado que vale para a pessoa hoje (o de todas as sedes ou o da lotação dela no dia, `FeriadosDaPessoa`, a mesma
 * regra da folha e da meta do dia na tela): a escala não vale, então saem os avisos que vêm dela (entrada, repouso no
 * horário da escala e "Jornada concluída", que já pedia meta positiva). Ficam os que vêm das batidas de quem trabalhou
 * no feriado: 6 horas sem repouso e intervalo mínimo concluído.
 */
class VerificadorAlertaPonto
{
    private const TOLERANCIA_MINUTOS = 5;
    private const LIMITE_CLT_MINUTOS = 360; // 6h CLT

    public function __construct(
        private readonly JornadaResolver $jornadaResolver,
        private readonly RegistroPontoRepository $registroRepository,
        private readonly FeriadoRepository $feriadoRepository,
        private readonly FeriadosDaPessoa $feriadosDaPessoa,
        private readonly CalculadoraJornada $calculadora,
        private readonly EscolhaDasBatidasDoDia $escolhaDasBatidas = new EscolhaDasBatidasDoDia(),
    ) {}

    /**
     * @return array{alertar: bool, mensagem: string, tipo: string, horario: string}
     */
    public function verificar(User $user, Tenant $tenant, \DateTimeImmutable $agora, ?JornadaTenant $jornadaTenant = null, ?JornadaColaborador $jornada = null): array
    {
        if (!$this->jornadaResolver->resolverAlertaHabilitado($jornada, $jornadaTenant)) {
            return $this->semAlerta();
        }

        $feriadoHoje = $this->feriadoDaPessoaHoje($user, $tenant, $agora);

        // As batidas de hoje NESTE escritório (auditoria 1.13): a de outro escritório da pessoa não muda o aviso daqui.
        $batidasDoDia = $this->registroRepository->findBatidasDoDia($user, $tenant, $agora);

        // As MESMAS batidas que a folha e o quadro de hoje contam (a escolha única). Até 21/09/2026 o
        // aviso ficava com a ÚLTIMA de cada tipo e podia mandar sair com a folha ainda devendo horas
        // (`docs/specs/ponto-folha-uma-batida-por-tipo.md` §10.5).
        $escolha = $this->escolhaDasBatidas->escolher($batidasDoDia, $agora);
        $porTipo = [];
        foreach ($escolha->escolhidas() as $batida) {
            $porTipo[$batida->getTipo()] = $batida;
        }

        $entradaRegistrada = isset($porTipo[RegistroPonto::TIPO_ENTRADA]);
        $repousoRegistrado = isset($porTipo[RegistroPonto::TIPO_REPOUSO]);
        $retornoRegistrado = isset($porTipo[RegistroPonto::TIPO_RETORNO]);
        $saidaRegistrada   = isset($porTipo[RegistroPonto::TIPO_SAIDA]);

        if (!$entradaRegistrada) {
            return $feriadoHoje ? $this->semAlerta() : [
                'alertar'  => true,
                'tipo'     => 'entrada',
                'mensagem' => 'Você ainda não registrou sua entrada hoje!',
                'horario'  => '',
            ];
        }

        if (!$repousoRegistrado) {
            // Verifica horário configurado (±5 min) — tem prioridade sobre CLT
            $alertaRepouso = $feriadoHoje ? null : $this->verificarRepousoPorHorario($jornada, $agora, $jornadaTenant);
            if ($alertaRepouso !== null) {
                return $alertaRepouso;
            }

            // Verifica 6h CLT contínuas
            $entrada = $porTipo[RegistroPonto::TIPO_ENTRADA];
            $minutosDesdeEntrada = (int) round(
                ($agora->getTimestamp() - $entrada->getDataHora()->getTimestamp()) / 60
            );
            if ($minutosDesdeEntrada >= self::LIMITE_CLT_MINUTOS) {
                return [
                    'alertar'  => true,
                    'tipo'     => 'aviso_repouso_6h',
                    'mensagem' => 'Você está trabalhando há mais de 6 horas sem registrar repouso. Registre o repouso ou encerre a jornada.',
                    'horario'  => '',
                ];
            }
        }

        if ($repousoRegistrado && !$retornoRegistrado) {
            $repouso = $porTipo[RegistroPonto::TIPO_REPOUSO];
            $minimoRepouso = $jornadaTenant?->getMinimoMinutosRepouso() ?? 60;
            $minutosDesdeRepouso = (int) round(
                ($agora->getTimestamp() - $repouso->getDataHora()->getTimestamp()) / 60
            );
            if ($minimoRepouso > 0 && $minutosDesdeRepouso >= $minimoRepouso) {
                return [
                    'alertar'  => true,
                    'tipo'     => 'retorno',
                    'mensagem' => 'Intervalo mínimo concluído. Hora de registrar seu retorno!',
                    'horario'  => '',
                ];
            }
        }

        if ($retornoRegistrado && !$saidaRegistrada) {
            // Feriado zera a meta, como na folha: sem "Jornada concluída".
            $metaDiaMinutos = $feriadoHoje ? 0 : $this->jornadaResolver->resolverMetaDia($jornada, $agora, $jornadaTenant);
            if ($metaDiaMinutos > 0) {
                $entrada = $porTipo[RegistroPonto::TIPO_ENTRADA];

                if ($escolha->temIntervalo()) {
                    $minutosAnteRepouso = (int) round(
                        ($escolha->repouso->getDataHora()->getTimestamp() - $entrada->getDataHora()->getTimestamp()) / 60
                    );
                    $minutosAposRetorno = (int) round(
                        ($agora->getTimestamp() - $escolha->retorno->getDataHora()->getTimestamp()) / 60
                    );
                    $totalTrabalhado = $minutosAnteRepouso + $minutosAposRetorno;
                } else {
                    // Sem intervalo mensurável (retorno antes do repouso) a folha conta o span inteiro.
                    $totalTrabalhado = (int) round(($agora->getTimestamp() - $entrada->getDataHora()->getTimestamp()) / 60);
                }

                if ($totalTrabalhado >= $metaDiaMinutos) {
                    return [
                        'alertar'  => true,
                        'tipo'     => 'saida',
                        'mensagem' => 'Jornada concluída. Hora de registrar sua saída!',
                        'horario'  => '',
                    ];
                }
            }
        }

        return $this->semAlerta();
    }

    /** Os feriados DESTE escritório, com o escritório escrito na consulta (como as batidas), filtrados para a pessoa. */
    private function feriadoDaPessoaHoje(User $user, Tenant $tenant, \DateTimeImmutable $agora): bool
    {
        $feriados = $this->feriadosDaPessoa->filtrar($this->feriadoRepository->doEscritorio($tenant), $user, $agora);

        return $this->calculadora->getFeriadoDoDia($agora, $feriados) !== null;
    }

    /**
     * @return array{alertar: bool, mensagem: string, tipo: string, horario: string}|null
     */
    private function verificarRepousoPorHorario(
        ?JornadaColaborador $jornada,
        \DateTimeImmutable $agora,
        ?JornadaTenant $jornadaTenant,
    ): ?array {
        $batidasEsperadas = $this->jornadaResolver->resolverBatidasEsperadasHoje($jornada, $agora, $jornadaTenant);
        $agoraMin = (int) $agora->format('H') * 60 + (int) $agora->format('i');

        foreach ($batidasEsperadas as $batida) {
            if ($batida['tipo'] !== RegistroPonto::TIPO_REPOUSO || $batida['horario'] === null) {
                continue;
            }
            [$h, $m] = explode(':', $batida['horario']);
            if (abs($agoraMin - ((int) $h * 60 + (int) $m)) <= self::TOLERANCIA_MINUTOS) {
                return ['alertar' => true, ...$batida];
            }
        }

        return null;
    }

    /**
     * @return array{alertar: bool, mensagem: string, tipo: string, horario: string}
     */
    private function semAlerta(): array
    {
        return ['alertar' => false, 'mensagem' => '', 'tipo' => '', 'horario' => ''];
    }
}
