<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Model\MarcacaoDoPonto;
use App\Ponto\Repository\RegistroPontoRepository;

/**
 * Alertas que não bloqueiam (spec docs/specs/ponto-desenho-0811.md, P21 e E11; desenho 16, `alertaPre` L455 a L464, a
 * janela "Antes de registrar" L288 e o `if (!forcar && this.ef('alertas'))` da batida, L498). Risco ALTO: ponto
 * eletrônico.
 *
 * Quem: a pessoa que bate o ponto, na tela do Ponto ou no atalho do cabeçalho.
 * Para quê: saber, antes de gravar, que a batida deixa o intervalo, a interjornada ou o descanso semanal fora da regra,
 * e registrar assim mesmo: "O alerta não impede o registro: a folha deve refletir o horário real.". Nada aqui recusa.
 *
 * Com a chave `ponto-alertas` ligada (Global e Escritório):
 * - retorno com o repouso de hoje abaixo do mínimo de intervalo do escritório (com a validação do repouso ligada na
 *   Jornada do escritório): alerta;
 * - entrada antes do mínimo de interjornada do escritório (com a validação da interjornada ligada), contado da última
 *   saída que fechou um turno aqui: alerta. A frase do "não conforme" só vai quando a saída é de outro dia: a folha
 *   compara a entrada do dia com a saída do dia anterior, e a saída de hoje (dois turnos) não entra nessa conta;
 * - entrada, primeira batida do dia, depois de 6 ou mais dias seguidos com registro aqui (o desenho olha 7 dias para
 *   trás): alerta. O desenho não tem validação própria para o descanso semanal: ele segue só a chave.
 * Só o primeiro alerta vai à janela, na ordem do desenho (interjornada antes do descanso semanal).
 *
 * Com a chave desligada não há alerta nem recusa: a batida grava direto, como no desenho, e a folha continua marcando o
 * que ficou fora da regra. A saída vinda de esquecimento, com ou sem a chave, só avisa depois de gravar (R4 da spec
 * ponto-esquecimento-nao-trava; aviso de antes da E11, que não é alerta desta etapa).
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
    /** A saída do mesmo dia (dois turnos): a folha não marca, e o alerta não promete a marca. */
    public const ALERTA_INTERJORNADA_DE_HOJE = 'Faltam %s para completar %s de descanso desde a saída de hoje.';
    public const ALERTA_DESCANSO_SEMANAL = 'Este será o %dº dia seguido com registro, sem o descanso semanal.';

    private const DE_ONTEM = 'de ontem';
    private const DA_DATA = 'de %s';

    /** @var array<string, string> texto da constante => chave no catálogo `ponto_servidor` (idioma-ponto-php B2) */
    public const CHAVE_DO_TEXTO = [
        self::NOTA => 'ponto_servidor.batida.alertas.nota',
        self::ALERTA_INTRAJORNADA => 'ponto_servidor.batida.alertas.intrajornada',
        self::ALERTA_INTERJORNADA => 'ponto_servidor.batida.alertas.interjornada',
        self::ALERTA_INTERJORNADA_DE_HOJE => 'ponto_servidor.batida.alertas.interjornada_de_hoje',
        self::ALERTA_DESCANSO_SEMANAL => 'ponto_servidor.batida.alertas.descanso_semanal',
        self::DE_ONTEM => 'ponto_servidor.batida.alertas.dia_de_ontem',
        self::DA_DATA => 'ponto_servidor.batida.alertas.dia_da_data',
    ];

    /** A chave do "N minutos" do tempo que falta (plural ICU, `{minutos}`); o português é o de {@see self::tempoQueFalta()}. */
    public const CHAVE_DOS_MINUTOS = 'ponto_servidor.batida.alertas.minutos';

    /** Dias seguidos com registro antes de hoje a partir dos quais a entrada avisa (desenho L461: `run >= 6`). */
    public const DIAS_SEGUIDOS_PARA_AVISAR = 6;

    /** Quantos dias antes de hoje o desenho olha (L461: `d <= 7`). */
    private const DIAS_OLHADOS = 7;

    private const DE_HOJE = 'de hoje';

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
        if ($tipo === RegistroPonto::TIPO_RETORNO) {
            return $avisa ? $this->doRetorno($user, $tenant, $jornada, $momento) : IntervaloDaBatida::livre();
        }

        return $this->daEntrada($user, $tenant, $jornada, $momento, $avisa);
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

    /** A chave no catálogo de uma das constantes de texto; qualquer outro texto é erro de programação. */
    public static function chaveDe(string $texto): string
    {
        return self::CHAVE_DO_TEXTO[$texto] ?? throw new \LogicException(sprintf('Texto do alerta sem chave no catálogo: "%s".', $texto));
    }

    /**
     * O texto de uma das constantes, com a chave para o controller traduzir.
     *
     * @param array<string, scalar|TextoDoPonto> $parametros
     */
    public static function texto(string $texto, array $parametros = []): TextoDoPonto
    {
        return TextoDoPonto::de(self::chaveDe($texto), $texto, $parametros);
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
        $tempo = self::textoDoTempoQueFalta($segundos);

        return $tempo instanceof TextoDoPonto ? $tempo->portugues : $tempo;
    }

    /**
     * O mesmo tempo de {@see self::tempoQueFalta()}: "2h10" (igual em qualquer idioma) ou, abaixo de 60 minutos, o texto
     * "N minutos" com a chave (plural ICU) para o controller traduzir.
     */
    public static function textoDoTempoQueFalta(int $segundos): string|TextoDoPonto
    {
        $minutos = intdiv(max($segundos, 1) + 59, 60);
        if ($minutos >= 60) {
            return sprintf('%dh%02d', intdiv($minutos, 60), $minutos % 60);
        }

        return new TextoDoPonto(self::CHAVE_DOS_MINUTOS, ['minutos' => $minutos], $minutos . ' minuto' . ($minutos > 1 ? 's' : ''));
    }

    /** O retorno com o primeiro repouso de hoje, até o momento da batida (a da fila não mede contra um repouso posterior). */
    private function doRetorno(User $user, Tenant $tenant, ?JornadaTenant $jornada, \DateTimeImmutable $momento): IntervaloDaBatida
    {
        $repouso = ($jornada?->isValidacaoRepousoHabilitada() ?? true) ? $this->registros->findRepousoDoDia($user, $tenant, $momento, $momento) : null;
        if ($repouso === null) {
            return IntervaloDaBatida::livre();
        }
        $minimo = $jornada?->getMinimoMinutosRepouso() ?? self::MINIMO_REPOUSO_PADRAO;
        $faltam = $minimo * 60 - ($momento->getTimestamp() - $repouso->getDataHora()->getTimestamp());

        return $faltam > 0
            ? new IntervaloDaBatida(textoDoAlerta: self::texto(self::ALERTA_INTRAJORNADA, ['faltam' => self::textoDoTempoQueFalta($faltam), 'minimo' => self::duracao($minimo)]), faltamDoIntervalo: $faltam)
            : IntervaloDaBatida::livre();
    }

    /**
     * A entrada: a interjornada (com a validação dela ligada na Jornada) e, sem alerta dela e com a chave ligada, o
     * descanso semanal. O aviso da saída de esquecimento vale com ou sem a chave (R4).
     */
    private function daEntrada(User $user, Tenant $tenant, ?JornadaTenant $jornada, \DateTimeImmutable $momento, bool $avisa): IntervaloDaBatida
    {
        $interjornada = ($jornada?->isValidacaoInterjornadaHabilitada() ?? true)
            ? $this->interjornada($user, $tenant, $jornada, $momento, $avisa)
            : IntervaloDaBatida::livre();
        if ($interjornada->textoDoAlerta !== null || !$avisa) {
            return $interjornada;
        }

        return new IntervaloDaBatida(textoDoAlerta: $this->descansoSemanal($user, $tenant, $momento), textoDoAviso: $interjornada->textoDoAviso);
    }

    /**
     * A interjornada medida da última saída que FECHOU um turno neste escritório, até o momento (a saída solta não conta,
     * R4). A saída vinda de esquecimento só avisa depois, com a conta de antes (minutos arredondados). A saída batida,
     * com a chave ligada: alerta (conta em segundos, a mesma do "não conforme" da folha); com ela desligada, nada.
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
        if ($this->saidaDeEsquecimento->veioDeEsquecimento($saida)) {
            return (int) round($passados / 60) < $minimo ? new IntervaloDaBatida(textoDoAviso: $this->avisoDoEsquecimento($saida, $minimo)) : IntervaloDaBatida::livre();
        }
        if (!$avisa) {
            return IntervaloDaBatida::livre();
        }
        $dia = self::diaDaSaida($saida, $momento);

        return new IntervaloDaBatida(textoDoAlerta: $dia === self::DE_HOJE
            ? self::texto(self::ALERTA_INTERJORNADA_DE_HOJE, ['faltam' => self::textoDoTempoQueFalta($faltam), 'minimo' => self::duracao($minimo)])
            : self::texto(self::ALERTA_INTERJORNADA, ['faltam' => self::textoDoTempoQueFalta($faltam), 'minimo' => self::duracao($minimo), 'dia' => $dia]));
    }

    private function avisoDoEsquecimento(MarcacaoDoPonto $saida, int $minimo): TextoDoPonto
    {
        return SaidaDeEsquecimento::avisoDaInterjornada($saida->getDataHora()->format('d/m'), $saida->getDataHora()->format('H:i'), intdiv($minimo, 60));
    }

    /** "de ontem", como o desenho; a saída do mesmo dia (dois turnos) diz "de hoje"; outra data, "de dd/mm". */
    private static function diaDaSaida(MarcacaoDoPonto $saida, \DateTimeImmutable $momento): string|TextoDoPonto
    {
        $dia = $saida->getDataHora()->format('Y-m-d');

        return match ($dia) {
            $momento->format('Y-m-d') => self::DE_HOJE,
            $momento->modify('-1 day')->format('Y-m-d') => self::texto(self::DE_ONTEM),
            default => self::texto(self::DA_DATA, ['data' => $saida->getDataHora()->format('d/m')]),
        };
    }

    /**
     * O 7º dia seguido (desenho L461): os dias antes de hoje com alguma batida aqui, de ontem para trás, até o primeiro
     * sem batida, olhando no máximo 7. Com 6 ou mais, "Este será o Nº dia seguido". Só na primeira batida do dia: a
     * entrada do segundo turno não repete o alerta, porque hoje já conta.
     */
    private function descansoSemanal(User $user, Tenant $tenant, \DateTimeImmutable $momento): ?TextoDoPonto
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

        return $seguidos >= self::DIAS_SEGUIDOS_PARA_AVISAR ? self::texto(self::ALERTA_DESCANSO_SEMANAL, ['dias' => (string) ($seguidos + 1)]) : null;
    }
}
