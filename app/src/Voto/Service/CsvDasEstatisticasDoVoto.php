<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\EstatisticasDoVotoOutput;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CSV `estatisticas-votacoes.csv` do Master (desenho H466, sem as colunas de ocorrências e avaliações que esperam a
 * W2-7b): UTF-8 com BOM (Excel), separador `;`, cada valor entre aspas, cabeçalho na primeira linha. Texto que começa
 * com `=`, `+`, `-` ou `@` ganha um apóstrofo para a planilha não o executar como fórmula (os títulos são digitados
 * por usuários de escritório).
 */
final class CsvDasEstatisticasDoVoto
{
    public const CABECALHO = 'escritorio;entidade;assembleia;pauta;categoria;situacao;encerramento;aptos;votantes;participacao;quorum';

    public function __construct(
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    public function gerar(EstatisticasDoVotoOutput $estatisticas): string
    {
        $linhas = [self::CABECALHO];
        foreach ($estatisticas->linhas as $l) {
            $linhas[] = implode(';', array_map(self::campo(...), [
                $l->escritorio,
                $l->entidade ?? '',
                $l->assembleia,
                $l->pauta,
                $l->categoria,
                $this->tradutor->trans('master.voto.estatisticas.situacao.' . $l->estado->value),
                $l->encerramento?->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i') ?? '',
                $l->aptos,
                $l->votantes,
                $l->participacao(),
                null === $l->quorum ? '' : $this->tradutor->trans('master.voto.estatisticas.quorum.' . $l->quorum),
            ]));
        }

        return "\u{FEFF}" . implode("\n", $linhas) . "\n";
    }

    private static function campo(string|int $valor): string
    {
        $texto = (string) $valor;
        if (1 === preg_match('/^[=+\-@\t\r]/', $texto)) {
            $texto = "'" . $texto;
        }

        return '"' . str_replace('"', '""', $texto) . '"';
    }
}
