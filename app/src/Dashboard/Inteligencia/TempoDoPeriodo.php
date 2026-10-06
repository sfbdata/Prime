<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * Posição de "hoje" dentro do período filtrado no Dashboard (De/Até), em dias corridos.
 *
 * Porte de `tempo()` e `fase()` do desenho (`bluejus-intelligence.js` L17-22 e L58-63):
 * total de dias (inclusivo), dias passados (0..total), restantes, fração e a fase do
 * período (início / meio / reta final / encerrado). Só existe com as DUAS datas válidas
 * e em ordem — sem período completo não há dia a contar, e o painel não inventa um.
 *
 * Datas lidas no mesmo formato estrito ('!Y-m-d') dos repositórios do Dashboard, para que
 * o motor conte dias exatamente sobre o período que de fato filtrou as contagens.
 */
final readonly class TempoDoPeriodo
{
    public const FASE_INICIO    = 'inicio';
    public const FASE_MEIO      = 'meio';
    public const FASE_FINAL     = 'final';
    public const FASE_ENCERRADO = 'encerrado';

    /** Fração do período abaixo da qual ainda é "início" (bluejus-intelligence.js L52 e L60). */
    public const INICIO_FRACAO = 0.2;

    /** Dias restantes a partir dos quais é "reta final" (bluejus-intelligence.js L54 e L61). */
    public const FINAL_RESTANTES = 5;

    private function __construct(
        public \DateTimeImmutable $inicio,
        public \DateTimeImmutable $fim,
        public \DateTimeImmutable $hoje,
        /** Dias corridos do período, inclusivo (mínimo 1). */
        public int $total,
        /** Dias já decorridos contando hoje, entre 0 (antes de começar) e `total`. */
        public int $passados,
        public int $restantes,
        /** `passados / total`, entre 0 e 1. */
        public float $fracao,
        public bool $encerrado,
    ) {}

    /**
     * @param string $dataDe  'Y-m-d' (filtro data_de)
     * @param string $dataAte 'Y-m-d' (filtro data_ate)
     */
    public static function de(string $dataDe, string $dataAte, \DateTimeImmutable $hoje): ?self
    {
        $inicio = self::lerData($dataDe);
        $fim    = self::lerData($dataAte);
        if ($inicio === null || $fim === null || $fim < $inicio) {
            return null;
        }

        // Só a data de hoje importa (sem hora e sem fuso): compara dia com dia.
        $hojeDia = self::lerData($hoje->format('Y-m-d'));
        if ($hojeDia === null) {
            return null;
        }

        // JS: total = max(1, round((fim − inicio) / DIA) + 1)
        $total = max(1, (int) $inicio->diff($fim)->days + 1);

        // JS: passados = max(0, min(total, round((hoje − inicio) / DIA) + 1))
        $sinal    = $hojeDia < $inicio ? -1 : 1;
        $decorrido = $sinal * (int) $inicio->diff($hojeDia)->days + 1;
        $passados  = max(0, min($total, $decorrido));

        return new self(
            inicio:    $inicio,
            fim:       $fim,
            hoje:      $hojeDia,
            total:     $total,
            passados:  $passados,
            restantes: $total - $passados,
            fracao:    $passados / $total,
            encerrado: $passados >= $total,
        );
    }

    /** Fase do período, na ordem de precedência do desenho (L58-63). */
    public function fase(): string
    {
        if ($this->encerrado) {
            return self::FASE_ENCERRADO;
        }
        if ($this->fracao < self::INICIO_FRACAO) {
            return self::FASE_INICIO;
        }
        if ($this->restantes <= self::FINAL_RESTANTES) {
            return self::FASE_FINAL;
        }

        return self::FASE_MEIO;
    }

    private static function lerData(string $valor): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        // UTC: conta de dias corridos, sem horário de verão no meio (mesmo critério do UseCase).
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new \DateTimeZone('UTC'));
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $data;
    }
}
