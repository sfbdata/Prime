<?php

namespace App\Service\Ponto;

use App\Entity\Auth\User;
use App\Entity\Ponto\EscalaTrabalho;
use App\Entity\Ponto\Feriado;
use App\Entity\Ponto\JustificativaPonto;
use App\Entity\Ponto\RegistroPonto;
use App\Repository\Ponto\JustificativaPontoRepository;
use App\Repository\Ponto\RegistroPontoRepository;

class FolhaPontoBuilder
{
    public function __construct(
        private readonly CalculadoraJornada $calculadora,
        private readonly RegistroPontoRepository $registroPontoRepository,
        private readonly JustificativaPontoRepository $justificativaPontoRepository,
    ) {}

    /**
     * @param RegistroPonto[] $batidas
     * @param Feriado[] $feriados
     * @param array<string, JustificativaPonto> $justificativasDoMes Justificativas indexadas por 'Y-m-d'
     * @return array<int, array{diaMes: string, diaSemana: string, entrada: string, repouso: string, retorno: string, saida: string, fimSemana: bool, minutosTrabalhadosDia: int|null, saldoDia: int|null, saldoAcumulado: int|null, justificadoDia: bool, justificativa: JustificativaPonto|null}>
     */
    public function buildRows(
        \DateTimeImmutable $inicioMes,
        \DateTimeImmutable $fimMes,
        array $batidas,
        bool $includeEmptyDays = true,
        bool $orderDesc = false,
        ?EscalaTrabalho $escala = null,
        array $feriados = [],
        array $justificativasDoMes = []
    ): array {
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

        for ($dia = $inicioMes; $dia <= $fimMes; $dia = $dia->modify('+1 day')) {
            $chaveDia = $dia->format('Y-m-d');
            $indiceDiaSemana = (int) $dia->format('N');
            $diaFuturo = $dia > $hoje;

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
                'fimSemana'  => $indiceDiaSemana >= 6,
                'domingo'    => $indiceDiaSemana === 7,
                'isFeriado'  => false,
                'minutosTrabalhadosDia' => null,
                'saldoDia'       => null,
                'saldoAcumulado' => null,
                'justificadoDia' => false,
                'justificativa'  => $justificativasDoMes[$chaveDia] ?? null,
            ];

            if ($escala !== null) {
                $feriadoDoDia = $this->calculadora->getFeriadoDoDia($dia, $feriados);
                if ($diaFuturo) {
                    // Dias futuros: não exibe saldo nem soma no banco
                    $row['minutosTrabalhadosDia'] = null;
                    $row['saldoDia']              = null;
                    $row['saldoAcumulado']        = null;
                } elseif ($feriadoDoDia !== null) {
                    $row['isFeriado']            = true;
                    $row['diaSemana']            = 'FERIADO - ' . $row['diaSemana'];
                    $row['minutosTrabalhadosDia'] = 0;
                    $row['saldoDia']              = 0;
                    $row['saldoAcumulado']        = $saldoAcumulado;
                } elseif ($indiceDiaSemana === 7) {
                    $row['minutosTrabalhadosDia'] = 0;
                    $row['saldoDia']              = 0;
                    $row['saldoAcumulado']        = $saldoAcumulado;
                } else {
                    $batidasDoDia = isset($registrosPorDia[$chaveDia])
                        ? array_values($registrosPorDia[$chaveDia])
                        : [];

                    $temSaida = isset($registrosPorDia[$chaveDia][RegistroPonto::TIPO_SAIDA]);
                    $diaHoje  = $dia->format('Y-m-d') === $hoje->format('Y-m-d');

                    // Dia atual sem saída: mostra horas trabalhadas mas não saldo nem banco
                    if ($diaHoje && !$temSaida) {
                        $minutos = $this->calculadora->calcularMinutosTrabalhados($batidasDoDia);
                        $row['minutosTrabalhadosDia'] = $minutos;
                        $row['saldoDia']              = null;
                        $row['saldoAcumulado']        = null;
                    } else {
                        $minutos = $this->calculadora->calcularMinutosTrabalhados($batidasDoDia);
                        $saldoDia = $this->calculadora->calcularSaldoDia($escala->getUser(), $dia, $batidasDoDia, $escala, $feriados);

                        $justificativaDoDia = $justificativasDoMes[$chaveDia] ?? null;
                        $justificadoDia = false;
                        if ($justificativaDoDia !== null && $justificativaDoDia->getStatus() === 'abonado' && $saldoDia < 0) {
                            $saldoDia = 0;
                            $justificadoDia = true;
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
     * Calcula o saldo acumulado do banco de horas para o ano inteiro.
     *
     * Regras:
     * - Começa no max(createdAt do usuário, 01/01/ano)
     * - Termina no min(hoje, 31/12/ano) — dias futuros nunca contam
     *
     * @param Feriado[] $feriados
     */
    public function calcularSaldoAnual(User $user, int $ano, array $feriados): int
    {
        $escala = $user->getEscalaTrabalho();
        if ($escala === null) {
            return 0;
        }

        $hoje = new \DateTimeImmutable('today');

        $createdAt = $user->getCreatedAt();
        if ($createdAt === null) {
            return 0;
        }
        $inicioCadastro = \DateTimeImmutable::createFromInterface($createdAt)->setTime(0, 0, 0);

        $inicioAno = new \DateTimeImmutable(sprintf('%04d-01-01', $ano));
        $inicio = $inicioCadastro > $inicioAno ? $inicioCadastro : $inicioAno;

        $fimAno = new \DateTimeImmutable(sprintf('%04d-12-31', $ano));
        $fim = $hoje < $fimAno ? $hoje : $fimAno;

        if ($inicio > $fim) {
            return 0;
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
                $escala,
                $feriados,
                $justificativas
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

        return $saldoTotal;
    }
}
