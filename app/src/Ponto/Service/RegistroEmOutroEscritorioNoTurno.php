<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;

/**
 * Os dias da folha de um escritório em que a pessoa teve batida em OUTRO escritório enquanto o ponto daqui estava
 * aberto (padrão provisório do dono, 23/10, para o "ponto aberto nos dois escritórios"; spec
 * `ponto-esquecimento-nao-trava.md` R8). Só sinaliza na tela do admin: não diz qual escritório nem muda número.
 *
 * Aberto = de uma entrada ou retorno daqui até a próxima ida ao repouso ou saída daqui, no máximo
 * `TurnoAbertoNoEscritorio::JANELA_DO_TURNO_HORAS` depois (a entrada esquecida não fica aberta para sempre). O dia
 * marcado é o da entrada ou retorno que abriu o turno, que é a linha da folha. Do outro escritório só se leem os
 * horários (`RegistroPontoRepository::horariosEmOutrosEscritorios`), com o filtro de escritório suspenso de propósito.
 */
final class RegistroEmOutroEscritorioNoTurno
{
    private const ABREM = [RegistroPonto::TIPO_ENTRADA, RegistroPonto::TIPO_RETORNO];

    public function __construct(
        private readonly RegistroPontoRepository $registros,
    ) {
    }

    /** @return array<string, true> chave AAAA-MM-DD dos dias sinalizados da competência AAAA-MM */
    public function daCompetencia(User $user, Tenant $escritorio, string $competencia): array
    {
        $inicio = \DateTimeImmutable::createFromFormat('!Y-m', $competencia);
        if ($inicio === false) {
            return [];
        }
        $fim = $inicio->modify('last day of this month')->setTime(23, 59, 59);
        $folga = $fim->modify(sprintf('+%d hours', TurnoAbertoNoEscritorio::JANELA_DO_TURNO_HORAS));
        $deFora = $this->registros->horariosEmOutrosEscritorios($user, $escritorio, $inicio, $folga);
        if ($deFora === []) {
            return [];
        }

        $dias = [];
        foreach ($this->turnos($this->registros->batidasDoPeriodoNoEscritorio($user, $escritorio, $inicio, $folga)) as [$abriu, $fechou]) {
            if ($abriu > $fim) {
                continue;
            }
            foreach ($deFora as $horario) {
                if ($horario > $abriu && $horario < $fechou) {
                    $dias[$abriu->format('Y-m-d')] = true;
                    break;
                }
            }
        }

        return $dias;
    }

    /**
     * @param list<RegistroPonto> $batidas em ordem de horário
     *
     * @return list<array{\DateTimeImmutable, \DateTimeImmutable}> cada turno aberto aqui: [abriu, fechou)
     */
    private function turnos(array $batidas): array
    {
        $turnos = [];
        $aberto = null;
        foreach ($batidas as $batida) {
            $quando = \DateTimeImmutable::createFromInterface($batida->getDataHora());
            if (in_array($batida->getTipo(), self::ABREM, true)) {
                $aberto ??= $quando;   // reentrada com o turno já aberto não reabre
                continue;
            }
            if ($aberto !== null) {
                $turnos[] = [$aberto, min($quando, $this->limite($aberto))];
                $aberto = null;
            }
        }
        if ($aberto !== null) {
            $turnos[] = [$aberto, $this->limite($aberto)];
        }

        return $turnos;
    }

    private function limite(\DateTimeImmutable $abriu): \DateTimeImmutable
    {
        return $abriu->modify(sprintf('+%d hours', TurnoAbertoNoEscritorio::JANELA_DO_TURNO_HORAS));
    }
}
