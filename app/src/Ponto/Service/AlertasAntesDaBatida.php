<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;

/**
 * Alertas que não bloqueiam (spec docs/specs/ponto-desenho-0811.md, P21 e E11; desenho 16, `alertaPre` L455 a L464 e a
 * janela "Antes de registrar" L288). Risco ALTO: ponto eletrônico.
 *
 * Quem: a pessoa que bate o ponto, na tela do Ponto ou no atalho do cabeçalho.
 * Para quê: saber, antes de gravar, que a batida deixa o intervalo, a interjornada ou o descanso semanal fora da regra,
 * e registrar assim mesmo: "O alerta não impede o registro: a folha deve refletir o horário real.".
 *
 * Com as validações da Jornada do escritório ligadas e a chave `ponto-alertas` ligada (Global e Escritório):
 * - retorno com o repouso de hoje abaixo do mínimo de intervalo do escritório: alerta;
 * - entrada antes do mínimo de interjornada do escritório, contado da última saída que fechou um turno aqui: alerta (a
 *   saída vinda de esquecimento continua só avisando depois, R4 da spec ponto-esquecimento-nao-trava);
 * - entrada depois de 6 ou mais dias seguidos com registro aqui (o desenho olha 7 dias para trás): alerta. O desenho
 *   não tem chave própria para o descanso semanal; ele segue a validação do descanso entre jornadas.
 * Só o primeiro alerta vai à janela, na ordem do desenho (interjornada antes do descanso semanal).
 *
 * Com a chave desligada a janela não existe e vale a regra de antes da E11 (a decisão que preserva o sistema de hoje):
 * o retorno e a entrada abaixo do mínimo são recusados, com as mesmas frases; o descanso semanal não é olhado.
 *
 * Os mínimos são os da Jornada do escritório (o desenho fixa 1h e 11h); sem Jornada, os padrões de sempre (60 e 660
 * minutos). Tudo neste escritório: as batidas de outro escritório da mesma pessoa não entram na conta.
 */
final class AlertasAntesDaBatida
{
    /** O campo do envio da batida que confirma o alerta ("Registrar assim mesmo"). */
    public const CAMPO_DA_CONFIRMACAO = 'confirmarAlerta';

    /** A frase fixa da janela, abaixo do alerta (desenho L288). */
    public const NOTA = 'O alerta não impede o registro: a folha deve refletir o horário real.';

    public const ALERTA_INTRAJORNADA = 'Faltam %s para completar o intervalo mínimo de %s. Se registrar agora, a folha marcará o intervalo intrajornada como não conforme.';
    public const ALERTA_INTERJORNADA = 'Faltam %s para completar %s de descanso desde a saída %s. Se registrar agora, a folha marcará a interjornada como não conforme.';
    public const ALERTA_DESCANSO_SEMANAL = 'Este será o %dº dia seguido com registro, sem o descanso semanal.';

    /** As recusas de antes da E11, que ficam para a chave desligada. */
    public const RECUSA_INTRAJORNADA = 'Intervalo mínimo de repouso é de %d minuto(s). Aguarde mais %d minuto(s).';
    public const RECUSA_INTERJORNADA = 'Interjornada mínima é de %dh. Aguarde mais %dh%02dm.';

    /** Dias seguidos com registro antes de hoje a partir dos quais a entrada avisa (desenho L461: `run >= 6`). */
    public const DIAS_SEGUIDOS_PARA_AVISAR = 6;

    /** Quantos dias antes de hoje o desenho olha (L461: `d <= 7`). */
    private const DIAS_OLHADOS = 7;

    private const MINIMO_REPOUSO_PADRAO = 60;
    private const MINIMO_INTERJORNADA_PADRAO = 660;

    public function __construct(
        private readonly RegistroPontoRepository $registros,
        private readonly SaidaDeEsquecimento $saidaDeEsquecimento,
        private readonly FuncoesDoPonto $funcoes,
    ) {
    }

    /** As regras de intervalo da batida de `$tipo` no momento dela, neste escritório. */
    public function avaliar(User $user, Tenant $tenant, string $tipo, \DateTimeImmutable $momento): IntervaloDaBatida
    {
        if ($tipo !== RegistroPonto::TIPO_RETORNO && $tipo !== RegistroPonto::TIPO_ENTRADA) {
            return IntervaloDaBatida::livre();
        }
        $avisa = $this->funcoes->vale(FuncoesDoPonto::ALERTAS, $tenant);
        $jornada = $tenant->getJornadaTenant();

        return $tipo === RegistroPonto::TIPO_RETORNO
            ? $this->doRetorno($user, $tenant, $jornada, $momento, $avisa)
            : $this->daEntrada($user, $tenant, $jornada, $momento, $avisa);
    }

    /**
     * O envio confirmou o alerta? Só o `true` do JSON vale.
     *
     * @param array<mixed> $dados o JSON da batida
     */
    public static function confirmou(array $dados): bool
    {
        return ($dados[self::CAMPO_DA_CONFIRMACAO] ?? false) === true;
    }

    /** O mínimo no formato do aviso do intervalo do cartão (E1, `formatarMinimo`): "1h", "30min", "1h30", "11h". */
    public static function duracao(int $minutos): string
    {
        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;
        if ($horas === 0) {
            return $resto . 'min';
        }

        return $resto === 0 ? $horas . 'h' : sprintf('%dh%02d', $horas, $resto);
    }

    /**
     * Quanto falta, no formato do desenho (`ft`, L456): minutos arredondados para cima; 60 ou mais vira "2h10"; abaixo,
     * "18 minutos" ou "1 minuto".
     */
    public static function tempoQueFalta(int $segundos): string
    {
        $minutos = intdiv(max($segundos, 1) + 59, 60);
        if ($minutos >= 60) {
            return sprintf('%dh%02d', intdiv($minutos, 60), $minutos % 60);
        }

        return $minutos . ' minuto' . ($minutos > 1 ? 's' : '');
    }

    /** O retorno com o primeiro repouso de hoje, até o momento da batida (a da fila não mede contra um repouso posterior). */
    private function doRetorno(User $user, Tenant $tenant, ?JornadaTenant $jornada, \DateTimeImmutable $momento, bool $avisa): IntervaloDaBatida
    {
        $repouso = ($jornada?->isValidacaoRepousoHabilitada() ?? true) ? $this->registros->findRepousoDoDia($user, $tenant, $momento, $momento) : null;
        if ($repouso === null) {
            return IntervaloDaBatida::livre();
        }
        $minimo = $jornada?->getMinimoMinutosRepouso() ?? self::MINIMO_REPOUSO_PADRAO;
        $passados = $momento->getTimestamp() - $repouso->getDataHora()->getTimestamp();
        if ($avisa) {
            $faltam = $minimo * 60 - $passados;

            return $faltam > 0
                ? new IntervaloDaBatida(alerta: sprintf(self::ALERTA_INTRAJORNADA, self::tempoQueFalta($faltam), self::duracao($minimo)))
                : IntervaloDaBatida::livre();
        }
        $minutos = (int) round($passados / 60);

        return $minutos < $minimo
            ? new IntervaloDaBatida(recusa: sprintf(self::RECUSA_INTRAJORNADA, $minimo, $minimo - $minutos))
            : IntervaloDaBatida::livre();
    }

    /** A entrada: a interjornada e, sem alerta dela e com a chave ligada, o descanso semanal. */
    private function daEntrada(User $user, Tenant $tenant, ?JornadaTenant $jornada, \DateTimeImmutable $momento, bool $avisa): IntervaloDaBatida
    {
        if (!($jornada?->isValidacaoInterjornadaHabilitada() ?? true)) {
            return IntervaloDaBatida::livre();
        }
        $interjornada = $this->interjornada($user, $tenant, $jornada, $momento, $avisa);
        if ($interjornada->recusa !== null || $interjornada->alerta !== null || !$avisa) {
            return $interjornada;
        }

        return new IntervaloDaBatida(alerta: $this->descansoSemanal($user, $tenant, $momento), avisoDepois: $interjornada->avisoDepois);
    }

    /**
     * A interjornada medida da última saída que FECHOU um turno neste escritório, até o momento (a saída solta não conta,
     * R4). A saída vinda de esquecimento só avisa depois, com a conta de antes (minutos arredondados). A saída batida:
     * alerta com a chave ligada (conta em segundos, a mesma do "não conforme" da folha), recusa com ela desligada.
     */
    private function interjornada(User $user, Tenant $tenant, ?JornadaTenant $jornada, \DateTimeImmutable $momento, bool $avisa): IntervaloDaBatida
    {
        $saida = $this->registros->ultimaSaidaQueFechouTurno($user, $tenant, TurnoAbertoNoEscritorio::JANELA_DO_TURNO_HORAS, $momento);
        if ($saida === null) {
            return IntervaloDaBatida::livre();
        }
        $minimo = $jornada?->getMinimoMinutosInterjornada() ?? self::MINIMO_INTERJORNADA_PADRAO;
        $passados = $momento->getTimestamp() - $saida->getDataHora()->getTimestamp();
        $faltam = $minimo * 60 - $passados;
        if ($faltam <= 0) {
            return IntervaloDaBatida::livre();
        }
        $minutos = (int) round($passados / 60);
        if ($this->saidaDeEsquecimento->veioDeEsquecimento($saida)) {
            return $minutos < $minimo ? new IntervaloDaBatida(avisoDepois: $this->avisoDoEsquecimento($saida, $minimo)) : IntervaloDaBatida::livre();
        }
        if ($avisa) {
            return new IntervaloDaBatida(alerta: sprintf(self::ALERTA_INTERJORNADA, self::tempoQueFalta($faltam), self::duracao($minimo), self::diaDaSaida($saida, $momento)));
        }
        $falta = $minimo - $minutos;

        return $minutos < $minimo
            ? new IntervaloDaBatida(recusa: sprintf(self::RECUSA_INTERJORNADA, intdiv($minimo, 60), intdiv($falta, 60), $falta % 60))
            : IntervaloDaBatida::livre();
    }

    private function avisoDoEsquecimento(RegistroPonto $saida, int $minimo): string
    {
        return sprintf(SaidaDeEsquecimento::AVISO_INTERJORNADA, $saida->getDataHora()->format('d/m'), $saida->getDataHora()->format('H:i'), intdiv($minimo, 60));
    }

    /** "de ontem", como o desenho; a saída do mesmo dia (dois turnos) diz "de hoje"; outra data, "de dd/mm". */
    private static function diaDaSaida(RegistroPonto $saida, \DateTimeImmutable $momento): string
    {
        $dia = $saida->getDataHora()->format('Y-m-d');

        return match ($dia) {
            $momento->format('Y-m-d') => 'de hoje',
            $momento->modify('-1 day')->format('Y-m-d') => 'de ontem',
            default => 'de ' . $saida->getDataHora()->format('d/m'),
        };
    }

    /**
     * O 7º dia seguido (desenho L461): os dias antes de hoje com alguma batida aqui, de ontem para trás, até o primeiro
     * sem batida, olhando no máximo 7. Com 6 ou mais, "Este será o Nº dia seguido". Só na primeira batida do dia: a
     * entrada do segundo turno não repete o alerta, porque hoje já conta.
     */
    private function descansoSemanal(User $user, Tenant $tenant, \DateTimeImmutable $momento): ?string
    {
        $hoje = $momento->setTime(0, 0);
        $batidas = $this->registros->batidasDoPeriodoNoEscritorio($user, $tenant, $hoje->modify(sprintf('-%d days', self::DIAS_OLHADOS)), $momento);
        $diasComBatida = [];
        foreach ($batidas as $batida) {
            $diasComBatida[$batida->getDataHora()->format('Y-m-d')] = true;
        }
        if (isset($diasComBatida[$hoje->format('Y-m-d')])) {
            return null;
        }
        $seguidos = 0;
        while ($seguidos < self::DIAS_OLHADOS && isset($diasComBatida[$hoje->modify(sprintf('-%d days', $seguidos + 1))->format('Y-m-d')])) {
            ++$seguidos;
        }

        return $seguidos >= self::DIAS_SEGUIDOS_PARA_AVISAR ? sprintf(self::ALERTA_DESCANSO_SEMANAL, $seguidos + 1) : null;
    }
}
