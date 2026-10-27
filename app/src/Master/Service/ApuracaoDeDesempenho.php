<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Master\DTO\ResumoDeEventosDeDesempenho;
use App\Master\Entity\RegistroDeBackup;

/**
 * Regra dos 8 indicadores da seção Desempenho (desenho 05 Perfis 1.2 VS 25.10, `opsVals` do `desemp`;
 * spec docs/specs/master-desempenho.md §1). Pura: recebe números, devolve valor e tom, sem I/O.
 *
 * O tom é a regra de cor do desenho: `ok` (verde), `atencao` (âmbar), `critico` (vermelho), `neutro`
 * (cor do texto) e `semdados` (cinza, quando não há medição). Quem formata texto é a tela.
 */
final class ApuracaoDeDesempenho
{
    public const TOM_OK = 'ok';
    public const TOM_ATENCAO = 'atencao';
    public const TOM_CRITICO = 'critico';
    public const TOM_NEUTRO = 'neutro';
    public const TOM_SEM_DADOS = 'semdados';

    public const DISPONIBILIDADE_MINIMA = 99.0;
    public const P95_ATENCAO_MS = 1500;
    public const P95_CRITICO_MS = 3000;
    public const LOGINS_FALHOS_CRITICO = 10;
    public const BACKUP_ATRASADO_HORAS = 26;

    /**
     * @return array<string, array<string, mixed>> indexado pela chave do indicador, na ordem do desenho
     */
    public function indicadores(
        ResumoDeEventosDeDesempenho $resumo,
        ?RegistroDeBackup $ultimoBackup,
        ?RegistroDeBackup $ultimaRestauracao,
        \DateTimeImmutable $agora,
    ): array {
        return [
            'disponibilidade' => $this->disponibilidade($resumo),
            'p95'             => $this->p95($resumo),
            'erros'           => ['total' => $resumo->erros, 'tom' => $resumo->erros > 0 ? self::TOM_CRITICO : self::TOM_OK],
            'loginsFalhos'    => $this->loginsFalhos($resumo),
            'uploadsFalhos'   => ['falhas' => $resumo->uploadsFalhos, 'total' => $resumo->uploads, 'tom' => self::TOM_NEUTRO],
            'ultimoBackup'    => $this->ultimoBackup($ultimoBackup, $agora),
            'restauracao'     => $this->restauracao($ultimaRestauracao),
            'servidor'        => ['tom' => self::TOM_SEM_DADOS],
        ];
    }

    /** @return array<string, mixed> */
    private function disponibilidade(ResumoDeEventosDeDesempenho $resumo): array
    {
        if ($resumo->pings === 0) {
            return ['percentual' => null, 'verificacoes' => 0, 'tom' => self::TOM_SEM_DADOS];
        }
        $percentual = round($resumo->pingsOk / $resumo->pings * 100, 1);

        return [
            'percentual'   => $percentual,
            'verificacoes' => $resumo->pings,
            'tom'          => $percentual < self::DISPONIBILIDADE_MINIMA ? self::TOM_CRITICO : self::TOM_OK,
        ];
    }

    /** @return array<string, mixed> */
    private function p95(ResumoDeEventosDeDesempenho $resumo): array
    {
        $ms = $resumo->p95Ms;
        $tom = match (true) {
            $ms === null                       => self::TOM_SEM_DADOS,
            $ms > self::P95_CRITICO_MS         => self::TOM_CRITICO,
            $ms > self::P95_ATENCAO_MS         => self::TOM_ATENCAO,
            default                            => self::TOM_OK,
        };

        return ['ms' => $ms, 'medidos' => $resumo->cargasMedidas, 'tom' => $tom];
    }

    /** @return array<string, mixed> */
    private function loginsFalhos(ResumoDeEventosDeDesempenho $resumo): array
    {
        return [
            'falhas' => $resumo->loginsFalhos,
            'total'  => $resumo->logins,
            'tom'    => $resumo->loginsFalhos > self::LOGINS_FALHOS_CRITICO ? self::TOM_CRITICO : self::TOM_NEUTRO,
        ];
    }

    /** @return array<string, mixed> */
    private function ultimoBackup(?RegistroDeBackup $backup, \DateTimeImmutable $agora): array
    {
        if ($backup === null) {
            return ['em' => null, 'horas' => null, 'tom' => self::TOM_SEM_DADOS];
        }
        $horas = (int) round(($agora->getTimestamp() - $backup->getRegistradoEm()->getTimestamp()) / 3600);

        return [
            'em'    => $backup->getRegistradoEm()->format(\DATE_ATOM),
            'horas' => max(0, $horas),
            'tom'   => $horas > self::BACKUP_ATRASADO_HORAS ? self::TOM_CRITICO : self::TOM_OK,
        ];
    }

    /** @return array<string, mixed> */
    private function restauracao(?RegistroDeBackup $teste): array
    {
        if ($teste === null) {
            return ['ok' => null, 'em' => null, 'tom' => self::TOM_SEM_DADOS];
        }

        return [
            'ok'  => $teste->isOk(),
            'em'  => $teste->getRegistradoEm()->format(\DATE_ATOM),
            'tom' => $teste->isOk() ? self::TOM_OK : self::TOM_CRITICO,
        ];
    }
}
