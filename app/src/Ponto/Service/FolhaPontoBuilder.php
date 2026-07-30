<?php

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;

class FolhaPontoBuilder
{
    public function __construct(
        private readonly CalculadoraJornada $calculadora,
        private readonly RegistroPontoRepository $registroPontoRepository,
        private readonly JustificativaPontoRepository $justificativaPontoRepository,
        private readonly LancamentoHorasPagasRepository $lancamentoHorasPagasRepository,
    ) {}

    /**
     * @param RegistroPonto[] $batidas
     * @param Feriado[] $feriados
     * @param array<string, JustificativaPonto> $justificativasDoMes Justificativas indexadas por 'Y-m-d'
     * @param ?\DateTimeInterface $inicioContagem Data da primeira batida do colaborador — início da contagem.
     *                                            `null` significa colaborador sem nenhum registro de ponto: nenhum
     *                                            dia conta. Dias anteriores a ela ficam fora (sem saldo, sem somar
     *                                            no banco). Admissão e data de cadastro não entram nessa conta.
     * @return array<int, array{diaMes: string, diaSemana: string, entrada: string, repouso: string, retorno: string, saida: string, fimSemana: bool, minutosTrabalhadosDia: int|null, saldoDia: int|null, saldoAcumulado: int|null, justificadoDia: bool, justificativa: JustificativaPonto|null, homeOffice: bool, antesDoPrimeiroRegistro: bool}>
     */
    public function buildRows(
        \DateTimeImmutable $inicioMes,
        \DateTimeImmutable $fimMes,
        array $batidas,
        bool $includeEmptyDays = true,
        bool $orderDesc = false,
        ?JornadaColaborador $jornada = null,
        array $feriados = [],
        array $justificativasDoMes = [],
        ?JornadaTenant $jornadaTenant = null,
        \DateTimeInterface|null|false $inicioContagem = false
    ): array {
        $this->exigirInicioContagem($inicioContagem, __FUNCTION__);
        $registrosPorDia = [];
        foreach ($batidas as $batida) {
            $chaveDia = $batida->getDataHora()->format('Y-m-d');
            $tipo = $batida->getTipo();

            if (!isset($registrosPorDia[$chaveDia])) {
                $registrosPorDia[$chaveDia] = [];
            }

            if (!isset($registrosPorDia[$chaveDia][$tipo])) {
                $registrosPorDia[$chaveDia][$tipo] = $batida;
                continue;
            }

            $registroAtual = $registrosPorDia[$chaveDia][$tipo];
            if (
                $tipo === RegistroPonto::TIPO_SAIDA
                && $batida->getDataHora() > $registroAtual->getDataHora()
            ) {
                $registrosPorDia[$chaveDia][$tipo] = $batida;
            }
        }

        $diasSemana = [
            1 => 'Segunda',
            2 => 'Terça',
            3 => 'Quarta',
            4 => 'Quinta',
            5 => 'Sexta',
            6 => 'Sábado',
            7 => 'Domingo',
        ];

        $rows = [];
        $saldoAcumulado = 0;
        $hoje = new \DateTimeImmutable('today');

        $inicioContagemNorm = $inicioContagem !== null
            ? \DateTimeImmutable::createFromInterface($inicioContagem)->setTime(0, 0, 0)
            : null;

        for ($dia = $inicioMes; $dia <= $fimMes; $dia = $dia->modify('+1 day')) {
            $chaveDia = $dia->format('Y-m-d');
            $indiceDiaSemana = (int) $dia->format('N');
            $diaFuturo = $dia > $hoje;
            // Sem primeiro registro, nenhum dia conta; com ele, os anteriores ficam de fora.
            $diaForaDaContagem = $inicioContagemNorm === null || $dia < $inicioContagemNorm;

            $row = [
                'diaMes'    => $dia->format('d'),
                'diaSemana' => $diasSemana[$indiceDiaSemana],
                'chaveDia'  => $chaveDia,
                'entrada'   => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_ENTRADA]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_ENTRADA]->getDataHora()->format('H:i:s') : '',
                'repouso'   => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO]->getDataHora()->format('H:i:s') : '',
                'retorno'   => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO]->getDataHora()->format('H:i:s') : '',
                'saida'     => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA])   ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA]->getDataHora()->format('H:i:s')   : '',
                'entradaId' => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_ENTRADA]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_ENTRADA]->getId() : null,
                'repousoId' => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO]->getId() : null,
                'retornoId' => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO]) ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO]->getId() : null,
                'saidaId'   => isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA])   ? $registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA]->getId()   : null,
                'fimSemana'       => $indiceDiaSemana >= 6,
                'domingo'         => $indiceDiaSemana === 7,
                'antesDoPrimeiroRegistro' => $diaForaDaContagem,
                'isFeriado'       => false,
                'nomeFeriado'     => null,
                'minutosIntervalo'      => null,
                'minutosTrabalhadosDia' => null,
                'saldoDia'       => null,
                'saldoAcumulado' => null,
                'justificadoDia' => false,
                'justificativa'  => $justificativasDoMes[$chaveDia] ?? null,
                'homeOffice'     => $this->diaTeveHomeOffice($registrosPorDia[$chaveDia] ?? []),
            ];

            if ($jornada !== null) {
                $feriadoDoDia = $this->calculadora->getFeriadoDoDia($dia, $feriados);
                if ($diaFuturo || $diaForaDaContagem) {
                    // Dia futuro ou anterior ao primeiro registro: não exibe saldo nem soma no banco
                    $row['minutosTrabalhadosDia'] = null;
                    $row['saldoDia']              = null;
                    $row['saldoAcumulado']        = null;
                } else {
                    if ($feriadoDoDia !== null) {
                        $row['isFeriado']   = true;
                        $row['nomeFeriado'] = $feriadoDoDia->getNome();
                        $row['diaSemana']   = 'FERIADO - ' . $row['diaSemana'];
                    }

                    $batidasDoDia = isset($registrosPorDia[$chaveDia])
                        ? array_values($registrosPorDia[$chaveDia])
                        : [];

                    $temSaida = isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA]);
                    $diaHoje  = $dia->format('Y-m-d') === $hoje->format('Y-m-d');

                    // minutosIntervalo: diferença entre retorno e repouso do dia
                    if (isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO])
                        && isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO])) {
                        $row['minutosIntervalo'] = $this->calculadora->diffMinutos(
                            $registrosPorDia[$chaveDia][RegistroPonto::TIPO_REPOUSO]->getDataHora(),
                            $registrosPorDia[$chaveDia][RegistroPonto::TIPO_RETORNO]->getDataHora()
                        );
                    }

                    // Dia atual sem saída: mostra horas trabalhadas mas não saldo nem banco
                    if ($diaHoje && !$temSaida) {
                        $minutos = $this->calculadora->calcularMinutosTrabalhados($batidasDoDia);
                        $row['minutosTrabalhadosDia'] = $minutos;
                        $row['saldoDia']              = null;
                        $row['saldoAcumulado']        = null;
                    } else {
                        $minutos = $this->calculadora->calcularMinutosTrabalhados($batidasDoDia);
                        $saldoDia = $this->calculadora->calcularSaldoDia($jornada->getUser(), $dia, $batidasDoDia, $jornada, $feriados, $jornadaTenant);

                        $justificativaDoDia = $justificativasDoMes[$chaveDia] ?? null;
                        $justificadoDia = false;
                        if ($justificativaDoDia !== null && $justificativaDoDia->getStatus() === 'abonado') {
                            if ($justificativaDoDia->getTipo() === 'falta_nao_justificada') {
                                $justificadoDia = true;
                            } elseif ($justificativaDoDia->isAbonoParcial()) {
                                $saldoDia += $justificativaDoDia->getMinutosAbonados();
                                $justificadoDia = true;
                            } elseif ($saldoDia < 0) {
                                $saldoDia = 0;
                                $justificadoDia = true;
                            }
                        }

                        $saldoAcumulado += $saldoDia;

                        $row['minutosTrabalhadosDia'] = $minutos;
                        $row['saldoDia']              = $saldoDia;
                        $row['saldoAcumulado']        = $saldoAcumulado;
                        $row['justificadoDia']        = $justificadoDia;
                        $row['justificativa']         = $justificativaDoDia;
                    }
                }
            }

            if (
                !$includeEmptyDays
                && $row['entrada'] === ''
                && $row['repouso'] === ''
                && $row['retorno'] === ''
                && $row['saida'] === ''
                && $row['justificativa'] === null
            ) {
                continue;
            }

            $rows[] = $row;
        }

        if ($orderDesc) {
            $rows = array_reverse($rows);
        }

        return $rows;
    }

    /**
     * Indica se o dia teve ao menos uma batida em home office (para o selo no espelho).
     *
     * @param array<string, RegistroPonto> $batidasDoDia
     */
    private function diaTeveHomeOffice(array $batidasDoDia): bool
    {
        foreach ($batidasDoDia as $batida) {
            if ($batida->isHomeOffice()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distingue "não informado" (erro de chamada) de `null` ("colaborador sem registro de ponto").
     * Sem esta guarda, esquecer o parâmetro zeraria a folha em SILÊNCIO — e a semântica do valor
     * omitido acabou de se inverter (antes: conta tudo; agora: não conta nada), então uma chamada
     * antiga sobrevivendo a um merge daria saldo errado sem nenhum sinal.
     */
    private function exigirInicioContagem(\DateTimeInterface|null|false $inicioContagem, string $metodo): void
    {
        if ($inicioContagem === false) {
            throw new \InvalidArgumentException(sprintf(
                '%s: informe $inicioContagem (data da primeira batida do colaborador). '
                . 'null significa "sem nenhum registro de ponto", não "não informado".',
                $metodo
            ));
        }
    }

    /**
     * Mesma sentinela do $inicioContagem, e pelo mesmo motivo: distingue "não informado" (erro de
     * chamada) de `null` ("não há tenant"). Enquanto o parâmetro tinha default `null`, qualquer
     * chamador que esquecesse o tenant perdia os lançamentos de horas pagas em SILÊNCIO — sem
     * exceção, sem log, com a folha aparentemente normal e o banco de horas errado.
     */
    private function exigirTenant(Tenant|null|false $tenant, string $metodo): ?Tenant
    {
        if ($tenant === false) {
            throw new \InvalidArgumentException(sprintf(
                '%s: informe $tenant (escritório do colaborador). '
                . 'null significa "não há tenant" — e então os lançamentos de horas pagas não somam.',
                $metodo
            ));
        }

        return $tenant;
    }

    /**
     * Calcula o saldo acumulado do banco de horas até o último dia do mês informado.
     * Útil para obter o "saldo anterior" antes da competência exportada.
     *
     * @param Feriado[] $feriados
     * @param Tenant|null|false $tenant Escritório do colaborador, obrigatório: `false` (omitido) é erro
     *                                  de chamada e lança; `null` significa "não há tenant" e então os
     *                                  lançamentos de horas pagas não somam.
     */
    public function calcularSaldoAteMes(User $user, int $ano, int $mes, array $feriados, ?JornadaTenant $jornadaTenant = null, \DateTimeInterface|null|false $inicioContagem = false, Tenant|null|false $tenant = false): int
    {
        $this->exigirInicioContagem($inicioContagem, __FUNCTION__);
        $tenantNorm = $this->exigirTenant($tenant, __FUNCTION__);

        // Horas pagas somam SEMPRE, antes de qualquer saída antecipada: um lançamento numa competência
        // anterior à primeira batida, ou de colaborador sem jornada, continua valendo.
        $horasPagas = $this->somarHorasPagasDoPeriodo($user, $tenantNorm, $ano, 1, $mes);

        $jornada = $user->getJornadaColaborador();
        if ($jornada === null) {
            return $horasPagas;
        }

        $hoje = new \DateTimeImmutable('today');

        // Sem nenhum registro de ponto não há o que contar.
        if ($inicioContagem === null) {
            return $horasPagas;
        }
        $inicioContagemNorm = \DateTimeImmutable::createFromInterface($inicioContagem)->setTime(0, 0, 0);

        $inicioAno = new \DateTimeImmutable(sprintf('%04d-01-01', $ano));
        $inicio = $inicioContagemNorm > $inicioAno ? $inicioContagemNorm : $inicioAno;

        $limiteMax = new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
        $limiteMax = $limiteMax->modify('last day of this month');
        $fim = $hoje < $limiteMax ? $hoje : $limiteMax;

        if ($inicio > $fim) {
            return $horasPagas;
        }

        $saldoTotal = 0;
        $mesAtual = (int) $inicio->format('m');
        $anoAtual = (int) $inicio->format('Y');
        $mesFim   = (int) $fim->format('m');
        $anoFim   = (int) $fim->format('Y');

        while ($anoAtual < $anoFim || ($anoAtual === $anoFim && $mesAtual <= $mesFim)) {
            $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01', $anoAtual, $mesAtual));
            $fimMes = $inicioMes->modify('last day of this month');

            $inicioEfetivo = $inicio > $inicioMes ? $inicio : $inicioMes;
            $fimEfetivo    = $fim < $fimMes ? $fim : $fimMes;

            $batidas = $this->registroPontoRepository->findByUserAndCompetencia($user, $anoAtual, $mesAtual);
            $justificativas = $this->justificativaPontoRepository->findByUserAndCompetenciaIndexed($user, $anoAtual, $mesAtual);

            $rows = $this->buildRows(
                $inicioEfetivo->setTime(0, 0, 0),
                $fimEfetivo->setTime(23, 59, 59),
                $batidas,
                true,
                false,
                $jornada,
                $feriados,
                $justificativas,
                $jornadaTenant,
                $inicioContagemNorm
            );

            if (!empty($rows)) {
                foreach (array_reverse($rows) as $row) {
                    if ($row['saldoAcumulado'] !== null) {
                        $saldoTotal += $row['saldoAcumulado'];
                        break;
                    }
                }
            }

            $mesAtual++;
            if ($mesAtual > 12) {
                $mesAtual = 1;
                $anoAtual++;
            }
        }

        return $saldoTotal + $horasPagas;
    }

    /**
     * Calcula o saldo acumulado do banco de horas para o ano inteiro.
     *
     * Regras:
     * - Começa no max(primeira batida do colaborador, 01/01/ano) — admissão/cadastro não contam
     * - Termina no min(hoje, 31/12/ano) — dias futuros nunca contam
     *
     * @param Feriado[] $feriados
     * @param Tenant|null|false $tenant Escritório do colaborador, obrigatório: `false` (omitido) é erro
     *                                  de chamada e lança; `null` significa "não há tenant" e então os
     *                                  lançamentos de horas pagas não somam.
     */
    public function calcularSaldoAnual(User $user, int $ano, array $feriados, ?JornadaTenant $jornadaTenant = null, \DateTimeInterface|null|false $inicioContagem = false, Tenant|null|false $tenant = false): int
    {
        $this->exigirInicioContagem($inicioContagem, __FUNCTION__);
        $tenantNorm = $this->exigirTenant($tenant, __FUNCTION__);

        // Horas pagas somam SEMPRE, antes de qualquer saída antecipada: um lançamento numa competência
        // anterior à primeira batida, ou de colaborador sem jornada, continua valendo.
        $horasPagas = $this->somarHorasPagasDoPeriodo($user, $tenantNorm, $ano, 1, 12);

        $jornada = $user->getJornadaColaborador();
        if ($jornada === null) {
            return $horasPagas;
        }

        $hoje = new \DateTimeImmutable('today');

        // Sem nenhum registro de ponto não há o que contar.
        if ($inicioContagem === null) {
            return $horasPagas;
        }
        $inicioContagemNorm = \DateTimeImmutable::createFromInterface($inicioContagem)->setTime(0, 0, 0);

        $inicioAno = new \DateTimeImmutable(sprintf('%04d-01-01', $ano));
        $inicio = $inicioContagemNorm > $inicioAno ? $inicioContagemNorm : $inicioAno;

        $fimAno = new \DateTimeImmutable(sprintf('%04d-12-31', $ano));
        $fim = $hoje < $fimAno ? $hoje : $fimAno;

        if ($inicio > $fim) {
            return $horasPagas;
        }

        $saldoTotal = 0;
        $mesAtual = (int) $inicio->format('m');
        $anoAtual = (int) $inicio->format('Y');
        $mesFim   = (int) $fim->format('m');
        $anoFim   = (int) $fim->format('Y');

        while ($anoAtual < $anoFim || ($anoAtual === $anoFim && $mesAtual <= $mesFim)) {
            $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01', $anoAtual, $mesAtual));
            $fimMes = $inicioMes->modify('last day of this month');

            $inicioEfetivo = $inicio > $inicioMes ? $inicio : $inicioMes;
            $fimEfetivo    = $fim < $fimMes ? $fim : $fimMes;

            $batidas = $this->registroPontoRepository->findByUserAndCompetencia($user, $anoAtual, $mesAtual);
            $justificativas = $this->justificativaPontoRepository->findByUserAndCompetenciaIndexed($user, $anoAtual, $mesAtual);

            $rows = $this->buildRows(
                $inicioEfetivo->setTime(0, 0, 0),
                $fimEfetivo->setTime(23, 59, 59),
                $batidas,
                true,
                false,
                $jornada,
                $feriados,
                $justificativas,
                $jornadaTenant,
                $inicioContagemNorm
            );

            if (!empty($rows)) {
                // Procura o último row com saldoAcumulado não-nulo (hoje sem saída retorna null)
                foreach (array_reverse($rows) as $row) {
                    if ($row['saldoAcumulado'] !== null) {
                        $saldoTotal += $row['saldoAcumulado'];
                        break;
                    }
                }
            }

            $mesAtual++;
            if ($mesAtual > 12) {
                $mesAtual = 1;
                $anoAtual++;
            }
        }

        return $saldoTotal + $horasPagas;
    }

    /**
     * Soma, com sinal, os lançamentos de horas pagas do colaborador numa competência.
     * Público porque as telas precisam exibir a linha "Horas pagas" do mês exibido.
     */
    public function somarHorasPagasDaCompetencia(User $user, ?Tenant $tenant, int $ano, int $mes): int
    {
        if ($tenant === null) {
            return 0;
        }

        return $this->lancamentoHorasPagasRepository->somarPorCompetencia($user, $tenant, $ano, $mes);
    }

    /**
     * Soma os lançamentos de um intervalo de meses do mesmo ano (inclusive nas duas pontas).
     *
     * Uma query só (`somarPorPeriodo`), não uma por mês: `calcularSaldoAnual` roda no painel
     * `/ponto`, que todo funcionário abre várias vezes por dia — eram 12 round-trips por load.
     */
    private function somarHorasPagasDoPeriodo(User $user, ?Tenant $tenant, int $ano, int $mesInicial, int $mesFinal): int
    {
        if ($tenant === null) {
            return 0;
        }

        return $this->lancamentoHorasPagasRepository->somarPorPeriodo($user, $tenant, $ano, $mesInicial, $mesFinal);
    }
}
