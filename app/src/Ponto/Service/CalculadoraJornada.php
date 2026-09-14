<?php

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\Feriado;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\RegistroPonto;

class CalculadoraJornada
{
    // Tolerância negativa: atrasos menores que este valor são ignorados (não descontam)
    private const TOLERANCIA_ATRASO_MINUTOS = 5;

    public function __construct(
        private readonly JornadaResolver $jornadaResolver,
    ) {}

    /**
     * Calcula o saldo do dia em minutos para um usuário.
     * Retorna positivo (hora extra), negativo (falta) ou 0 (dentro da tolerância).
     *
     * Regras de tolerância assimétrica:
     * - Horas extras: qualquer minuto a mais (>= 1 min) conta positivamente
     * - Atrasos: só desconta se a falta for >= TOLERANCIA_ATRASO_MINUTOS (5 min)
     *
     * @param RegistroPonto[] $batidas
     * @param Feriado[] $feriados
     */
    public function calcularSaldoDia(User $user, \DateTimeInterface $data, array $batidas, ?JornadaColaborador $jornada, array $feriados, ?JornadaTenant $jornadaTenant = null): int
    {
        // Registro incompleto não credita nem debita. Antes disto, um dia em que a pessoa bateu a
        // entrada e esqueceu o resto valia `0 - carga` = a jornada inteira negativa: em 07/2026 quatro
        // dias assim custaram −35:12 a uma colaboradora que estava no escritório. Fica antes da
        // tolerância de 5 min porque não há déficit a tolerar — não há medição.
        if ($this->registroIncompleto($batidas)) {
            return 0;
        }

        $indiceDia = (int) $data->format('N');
        $isFeriado = $this->isFeriado($data, $feriados);

        // Se o colaborador tem blocos, JornadaResolver decide; 0 significa fora da jornada
        $jornadaComBlocos = $jornada !== null && !$jornada->getBlocos()->isEmpty();
        $tenantComBlocos  = $jornadaTenant !== null && !$jornadaTenant->getBlocos()->isEmpty();

        if ($jornadaComBlocos || $tenantComBlocos) {
            $cargaEsperada = $isFeriado ? 0 : $this->jornadaResolver->resolverMetaDia($user, $data, $jornadaTenant);
        } else {
            // Lógica legada: usa campos planos de JornadaColaborador
            $isDiaTrabalho = $jornada ? in_array($indiceDia, $jornada->getDiasSemana(), true) : false;
            $ehSabado = $indiceDia === 6;

            if ($ehSabado && $jornada && in_array(6, $jornada->getDiasSemana(), true) && $jornada->getCargaHorariaSabado() !== null) {
                $cargaEsperada = $isFeriado ? 0 : $jornada->getCargaHorariaSabado();
            } else {
                $cargaEsperada = ($isDiaTrabalho && !$isFeriado) ? ($jornada?->getCargaHorariaDiaria() ?? 0) : 0;
            }
        }

        $minutosTrabalhados = $this->calcularMinutosTrabalhados($batidas);

        // Dias fora da escala (domingo, feriado, sábado não escalado, etc.):
        // sem meta, apenas crédito positivo — nunca gera saldo negativo
        if ($cargaEsperada === 0) {
            return $minutosTrabalhados;
        }

        $saldo = $minutosTrabalhados - $cargaEsperada;

        if ($saldo > 0) {
            return $saldo;
        }

        if (abs($saldo) < self::TOLERANCIA_ATRASO_MINUTOS) {
            return 0;
        }

        return $saldo;
    }

    /** Retorna o Feriado correspondente à data, ou null se não for feriado. */
    public function getFeriadoDoDia(\DateTimeInterface $data, array $feriados): ?Feriado
    {
        foreach ($feriados as $feriado) {
            if ($feriado->isRecorrente()) {
                if ($feriado->getData()->format('m-d') === $data->format('m-d')) {
                    return $feriado;
                }
            } else {
                if ($feriado->getData()->format('Y-m-d') === $data->format('Y-m-d')) {
                    return $feriado;
                }
            }
        }
        return null;
    }

    private function isFeriado(\DateTimeInterface $data, array $feriados): bool
    {
        return $this->getFeriadoDoDia($data, $feriados) !== null;
    }

    /**
     * O dia tem batida, mas não as que permitem apurar a jornada.
     *
     * **Dia sem batida nenhuma não é incompleto, é ausência**, e continua gerando débito. Sem essa
     * distinção a regra apagaria toda falta do sistema, que é o oposto do que ela existe para fazer.
     *
     * O que a apuração exige é **entrada e saída**, e só. Trabalhar sem tirar almoço é permitido, e
     * nesse dia o span inteiro é a jornada — a meta da escala já vem líquida do intervalo, então
     * quem ficou vale o que ficou. (Decisão do dono em 31/08/2026. Até então a forma vinha da
     * escala, e o dia batido só na entrada e na saída era descartado por falta das batidas de
     * intervalo — ver `docs/specs/ponto-registro-incompleto-entrada-saida.md`.)
     *
     * A exceção é ter batido **metade** do intervalo: quem registrou o `repouso` provou que saiu
     * para almoçar, mas não por quanto tempo ficou fora. Aí o span inteiro creditaria esse almoço,
     * que é o defeito que a frente de 05/08 removeu — então o dia segue pendente de correção.
     *
     * @param RegistroPonto[] $batidas
     */
    public function registroIncompleto(array $batidas): bool
    {
        if ($batidas === []) {
            return false;
        }

        $presentes = [];
        foreach ($batidas as $batida) {
            $presentes[$batida->getTipo()] = true;
        }

        if (!isset($presentes[RegistroPonto::TIPO_ENTRADA]) || !isset($presentes[RegistroPonto::TIPO_SAIDA])) {
            return true;
        }

        // Metade do intervalo batida: um `!==` entre dois booleanos é o XOR que separa "não bateu
        // almoço nenhum" (apurável) de "bateu só um lado" (duração do almoço desconhecida).
        return isset($presentes[RegistroPonto::TIPO_REPOUSO]) !== isset($presentes[RegistroPonto::TIPO_RETORNO]);
    }

    /**
     * @param RegistroPonto[] $batidas
     */
    public function calcularMinutosTrabalhados(array $batidas): int
    {
        $entrada = $this->primeira($batidas, RegistroPonto::TIPO_ENTRADA);
        $saida   = $this->ultima($batidas, RegistroPonto::TIPO_SAIDA);
        [$repouso, $retorno] = $this->parDoIntervalo($batidas);

        if (!$entrada) {
            return 0;
        }

        if ($repouso && $retorno && $saida) {
            return $this->diffMinutos($entrada, $repouso)
                 + $this->diffMinutos($retorno, $saida);
        }

        if ($saida) {
            return $this->diffMinutos($entrada, $saida);
        }

        return 0;
    }

    /**
     * O par (repouso, retorno) que delimita o intervalo, escolhido pela cronologia.
     *
     * Tomar simplesmente a primeira batida de cada tipo não serve, porque os dois lados precisam
     * casar entre si: com `repouso 09:00` (tipo errado), `repouso 12:00` e `retorno 13:00`, o
     * primeiro repouso apagaria três horas de manhã trabalhada. E tomar a última de cada tipo —
     * o que o sistema fazia até 14/09/2026 — deixava um `retorno` batido no fim do dia encolher a
     * tarde inteira para poucos minutos.
     *
     * A regra que sobra é o par ADJACENTE: o primeiro retorno que tenha algum repouso antes dele,
     * e o último repouso antes desse retorno. É o menor almoço compatível com as batidas, e as duas
     * pontas saem da mesma decisão em vez de serem escolhidas em separado.
     *
     * **Sem par válido, não há intervalo mensurável** e os dois voltam nulos: o dia cai no span
     * inteiro, que é a regra que o dono aprovou em 31/08/2026 para quem não bateu almoço nenhum.
     * Acontece quando os tipos estão trocados (bateu `retorno` antes de qualquer `repouso`), e aí
     * nenhuma escolha recupera a duração do almoço. ⚠️ O span credita esse almoço, mas está preso
     * ao tempo físico entre a entrada e a saída — o que a versão anterior desta função não estava:
     * o fallback que ela usava chegava a somar 509 minutos numa janela de 508, contando duas vezes
     * o trecho entre o retorno e o repouso.
     *
     * @param RegistroPonto[] $batidas
     * @return array{0: ?\DateTimeInterface, 1: ?\DateTimeInterface}
     */
    private function parDoIntervalo(array $batidas): array
    {
        $retornos = $this->horasDoTipo($batidas, RegistroPonto::TIPO_RETORNO);
        sort($retornos);

        foreach ($retornos as $retorno) {
            $repouso = $this->ultimaAntesDe($batidas, RegistroPonto::TIPO_REPOUSO, $retorno);
            if ($repouso !== null) {
                return [$repouso, $retorno];
            }
        }

        return [null, null];
    }

    /**
     * @param RegistroPonto[] $batidas
     * @return \DateTimeInterface[]
     */
    private function horasDoTipo(array $batidas, string $tipo): array
    {
        $horas = [];
        foreach ($batidas as $batida) {
            if ($batida->getTipo() === $tipo) {
                $horas[] = $batida->getDataHora();
            }
        }

        return $horas;
    }

    /**
     * @param RegistroPonto[] $batidas
     */
    private function ultimaAntesDe(array $batidas, string $tipo, \DateTimeInterface $limite): ?\DateTimeInterface
    {
        $escolhida = null;
        foreach ($this->horasDoTipo($batidas, $tipo) as $hora) {
            if ($hora >= $limite) {
                continue;
            }
            if ($escolhida === null || $hora > $escolhida) {
                $escolhida = $hora;
            }
        }

        return $escolhida;
    }

    /**
     * A primeira batida do tipo.
     *
     * @param RegistroPonto[] $batidas
     */
    private function primeira(array $batidas, string $tipo): ?\DateTimeInterface
    {
        $escolhida = null;
        foreach ($this->horasDoTipo($batidas, $tipo) as $hora) {
            if ($escolhida === null || $hora < $escolhida) {
                $escolhida = $hora;
            }
        }

        return $escolhida;
    }

    /**
     * A última batida do tipo. Só a saída usa isto: quem bate a saída, volta e bate de novo
     * trabalhou até o fim — nos outros três tipos a repetição é duplo clique ou tipo errado.
     *
     * @param RegistroPonto[] $batidas
     */
    private function ultima(array $batidas, string $tipo): ?\DateTimeInterface
    {
        $escolhida = null;
        foreach ($this->horasDoTipo($batidas, $tipo) as $hora) {
            if ($escolhida === null || $hora > $escolhida) {
                $escolhida = $hora;
            }
        }

        return $escolhida;
    }

    public function diffMinutos(\DateTimeInterface $inicio, \DateTimeInterface $fim): int
    {
        $diff = $inicio->diff($fim);
        return max(0, ($diff->days * 1440) + ($diff->h * 60) + $diff->i);
    }
}
