<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JornadaTenantRepository;

/**
 * Os trechos de trabalho, por dia da semana, da jornada efetiva de uma pessoa num escritório (spec seguranca-b-jornada
 * §3.2). A jornada efetiva sai da mesma cascata da folha (`JornadaResolver`): a pessoal do escritório, depois a padrão
 * dele, depois os campos legados da pessoal. Serve ao choque entre escritórios e à batida.
 */
final class IntervalosDeTrabalho
{
    /** Uma segunda-feira qualquer: o dia N da semana é esta data mais N - 1 dias. */
    private const UMA_SEGUNDA = '2024-01-01';

    public function __construct(
        private readonly JornadaResolver $resolver,
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly JornadaTenantRepository $padroes,
    ) {
    }

    /** @return list<IntervaloDeTrabalho> os trechos da pessoa no escritório, como estão gravados */
    public function daPessoaNoEscritorio(User $user, Tenant $tenant): array
    {
        return $this->daJornada($this->jornadas->findByUserETenant($user, $tenant), $this->padroes->doEscritorio($tenant));
    }

    /** @return list<IntervaloDeTrabalho> os trechos de uma jornada pessoal (gravada ou proposta) sobre a padrão */
    public function daJornada(?JornadaColaborador $pessoal, ?JornadaTenant $padrao): array
    {
        $intervalos = [];
        for ($dia = 1; $dia <= 7; ++$dia) {
            $data = (new \DateTimeImmutable(self::UMA_SEGUNDA))->modify('+' . ($dia - 1) . ' days');
            $horarios = $this->horariosDoDia($this->resolver->resolverBatidasEsperadasHoje($pessoal, $data, $padrao));
            array_push($intervalos, ...$this->trechos($dia, $horarios));
        }

        return $intervalos;
    }

    /**
     * @param array<array{horario: string|null, tipo: string, mensagem: string}> $batidas
     *
     * @return array<string, int> minutos de cada tipo de batida que a escala do dia prevê
     */
    private function horariosDoDia(array $batidas): array
    {
        $horarios = [];
        foreach ($batidas as $batida) {
            if ($batida['horario'] !== null && $batida['horario'] !== '') {
                $horarios[$batida['tipo']] = IntervaloDeTrabalho::minutos($batida['horario']);
            }
        }

        return $horarios;
    }

    /**
     * @param array<string, int> $h
     *
     * @return list<IntervaloDeTrabalho> entrada a repouso e retorno a saída; sem intervalo, entrada a saída
     */
    private function trechos(int $dia, array $h): array
    {
        if (!isset($h['entrada'], $h['saida'])) {
            return [];
        }
        $pares = isset($h['repouso'], $h['retorno'])
            ? [[$h['entrada'], $h['repouso']], [$h['retorno'], $h['saida']]]
            : [[$h['entrada'], $h['saida']]];

        $trechos = [];
        foreach ($pares as [$inicio, $fim]) {
            if ($fim > $inicio) {
                $trechos[] = new IntervaloDeTrabalho($dia, $inicio, $fim);
            }
        }

        return $trechos;
    }
}
