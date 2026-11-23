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
 * Dia sem escala para a pessoa, isto é, meta do dia 0 (`JornadaResolver::resolverMetaDia`, a mesma da tela e do
 * "Jornada concluída"): o feriado que vale para ela (o de todas as sedes ou o da lotação dela no dia,
 * `FeriadosDaPessoa`) e o dia que a escala não cobre (folga, domingo).
 * Nele saem os avisos que vêm da escala (entrada, repouso no horário da escala e "Jornada concluída"). Ficam os que
 * vêm das batidas de quem trabalhou mesmo assim: 6 horas sem repouso e intervalo mínimo concluído.
 *
 * Sem escala nenhuma (nem a da pessoa, nem a do escritório) a meta é 0 todo dia e os avisos da escala não saem. Na
 * escala antiga, sem blocos, o sábado segue o resolver e não a folha (que olha `diasSemana`). Com o aviso ligado,
 * nenhum desses casos existia em produção em 10/11/2026 (nem bloco com 0 minutos).
 */
class VerificadorAlertaPonto
{
    public const AVISO_SEM_ENTRADA = 'Você ainda não registrou sua entrada hoje!';
    public const AVISO_SEIS_HORAS_SEM_REPOUSO = 'Você está trabalhando há mais de 6 horas sem registrar repouso. Registre o repouso ou encerre a jornada.';
    public const AVISO_INTERVALO_CONCLUIDO = 'Intervalo mínimo concluído. Hora de registrar seu retorno!';
    public const AVISO_JORNADA_CONCLUIDA = 'Jornada concluída. Hora de registrar sua saída!';

    /**
     * @var array<string, string> mensagem em português => chave no catálogo `ponto_servidor`. `verificar()` segue
     *     devolvendo o português; o controller da resposta de tela (/ponto/alerta-horario) traduz pela chave. Nenhum
     *     desses textos é gravado (nem em Notification): só a resposta JSON os leva.
     */
    public const CHAVE_DO_TEXTO = [
        JornadaResolver::LEMBRETE_ENTRADA => 'ponto_servidor.lembrete.hora_entrada',
        JornadaResolver::LEMBRETE_REPOUSO => 'ponto_servidor.lembrete.hora_repouso',
        JornadaResolver::LEMBRETE_RETORNO => 'ponto_servidor.lembrete.hora_retorno',
        JornadaResolver::LEMBRETE_SAIDA => 'ponto_servidor.lembrete.hora_saida',
        self::AVISO_SEM_ENTRADA => 'ponto_servidor.lembrete.sem_entrada',
        self::AVISO_SEIS_HORAS_SEM_REPOUSO => 'ponto_servidor.lembrete.seis_horas_sem_repouso',
        self::AVISO_INTERVALO_CONCLUIDO => 'ponto_servidor.lembrete.intervalo_concluido',
        self::AVISO_JORNADA_CONCLUIDA => 'ponto_servidor.lembrete.jornada_concluida',
    ];

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

    /** A chave no catálogo de uma das mensagens do aviso; qualquer outro texto é erro de programação. */
    public static function chaveDe(string $mensagem): string
    {
        return self::CHAVE_DO_TEXTO[$mensagem] ?? throw new \LogicException(sprintf('Aviso do ponto sem chave no catálogo: "%s".', $mensagem));
    }

    /**
     * @return array{alertar: bool, mensagem: string, tipo: string, horario: string}
     */
    public function verificar(User $user, Tenant $tenant, \DateTimeImmutable $agora, ?JornadaTenant $jornadaTenant = null, ?JornadaColaborador $jornada = null): array
    {
        if (!$this->jornadaResolver->resolverAlertaHabilitado($jornada, $jornadaTenant)) {
            return $this->semAlerta();
        }

        $metaDiaMinutos = $this->metaDoDia($user, $tenant, $agora, $jornadaTenant, $jornada);
        $diaDaEscala = $metaDiaMinutos > 0;

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
            return !$diaDaEscala ? $this->semAlerta() : [
                'alertar'  => true,
                'tipo'     => 'entrada',
                'mensagem' => self::AVISO_SEM_ENTRADA,
                'horario'  => '',
            ];
        }

        if (!$repousoRegistrado) {
            // Verifica horário configurado (±5 min) — tem prioridade sobre CLT
            $alertaRepouso = $diaDaEscala ? $this->verificarRepousoPorHorario($jornada, $agora, $jornadaTenant) : null;
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
                    'mensagem' => self::AVISO_SEIS_HORAS_SEM_REPOUSO,
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
                    'mensagem' => self::AVISO_INTERVALO_CONCLUIDO,
                    'horario'  => '',
                ];
            }
        }

        if ($retornoRegistrado && !$saidaRegistrada) {
            if ($diaDaEscala) {
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
                        'mensagem' => self::AVISO_JORNADA_CONCLUIDA,
                        'horario'  => '',
                    ];
                }
            }
        }

        return $this->semAlerta();
    }

    /** A meta do dia para a pessoa: 0 no feriado que vale para ela e no dia que a escala não cobre. */
    private function metaDoDia(User $user, Tenant $tenant, \DateTimeImmutable $agora, ?JornadaTenant $jornadaTenant, ?JornadaColaborador $jornada): int
    {
        if ($this->feriadoDaPessoaHoje($user, $tenant, $agora)) {
            return 0;
        }

        return $this->jornadaResolver->resolverMetaDia($jornada, $agora, $jornadaTenant);
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
