<?php

declare(strict_types=1);

namespace App\Service\Ponto;

use App\Entity\Auth\User;
use App\Entity\Ponto\JornadaTenant;
use App\Entity\Ponto\RegistroPonto;
use App\Repository\Ponto\RegistroPontoRepository;

class VerificadorAlertaPonto
{
    private const TOLERANCIA_MINUTOS = 5;
    private const LIMITE_CLT_MINUTOS = 360; // 6h CLT

    public function __construct(
        private readonly JornadaResolver $jornadaResolver,
        private readonly RegistroPontoRepository $registroRepository,
    ) {}

    /**
     * @return array{alertar: bool, mensagem: string, tipo: string, horario: string}
     */
    public function verificar(User $user, \DateTimeImmutable $agora, ?JornadaTenant $jornadaTenant = null): array
    {
        if (!$this->jornadaResolver->resolverAlertaHabilitado($user, $jornadaTenant)) {
            return $this->semAlerta();
        }

        $batidasDoDia = $this->registroRepository->findBatidasDoDia($user, $agora);

        $porTipo = [];
        foreach ($batidasDoDia as $batida) {
            $porTipo[$batida->getTipo()] = $batida;
        }

        $entradaRegistrada = isset($porTipo[RegistroPonto::TIPO_ENTRADA]);
        $repousoRegistrado = isset($porTipo[RegistroPonto::TIPO_REPOUSO]);
        $retornoRegistrado = isset($porTipo[RegistroPonto::TIPO_RETORNO]);
        $saidaRegistrada   = isset($porTipo[RegistroPonto::TIPO_SAIDA]);

        if (!$entradaRegistrada) {
            return [
                'alertar'  => true,
                'tipo'     => 'entrada',
                'mensagem' => 'Você ainda não registrou sua entrada hoje!',
                'horario'  => '',
            ];
        }

        if (!$repousoRegistrado) {
            // Verifica horário configurado (±5 min) — tem prioridade sobre CLT
            $alertaRepouso = $this->verificarRepousoPorHorario($user, $agora, $jornadaTenant);
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
            $metaDiaMinutos = $this->jornadaResolver->resolverMetaDia($user, $agora, $jornadaTenant);
            if ($metaDiaMinutos > 0) {
                $entrada = $porTipo[RegistroPonto::TIPO_ENTRADA];
                $repouso = $porTipo[RegistroPonto::TIPO_REPOUSO];
                $retorno = $porTipo[RegistroPonto::TIPO_RETORNO];

                $minutosAnteRepouso  = (int) round(
                    ($repouso->getDataHora()->getTimestamp() - $entrada->getDataHora()->getTimestamp()) / 60
                );
                $minutosAposRetorno  = (int) round(
                    ($agora->getTimestamp() - $retorno->getDataHora()->getTimestamp()) / 60
                );
                $totalTrabalhado = $minutosAnteRepouso + $minutosAposRetorno;

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

    /**
     * @return array{alertar: bool, mensagem: string, tipo: string, horario: string}|null
     */
    private function verificarRepousoPorHorario(
        User $user,
        \DateTimeImmutable $agora,
        ?JornadaTenant $jornadaTenant,
    ): ?array {
        $batidasEsperadas = $this->jornadaResolver->resolverBatidasEsperadasHoje($user, $agora, $jornadaTenant);
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
