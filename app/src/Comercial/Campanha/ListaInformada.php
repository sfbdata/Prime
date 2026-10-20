<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;

/**
 * As listas que o Master informa (spec §8.5): o CSV, lido no servidor com a mesma regra do dc (L.545), e os e-mails
 * digitados no Individual (L.534). Só os limites e a leitura; a autorização (B1) e o descadastro são do
 * {@see PublicosDaCampanha}.
 *
 * CSV (dc L.545): uma linha por pessoa; as colunas se separam por `;`, `,` ou tabulação; o e-mail é a primeira coluna
 * com `@` e o nome é a primeira outra coluna preenchida, sem aspas. Até 5.000 linhas e 1 MB. Individual (dc L.534):
 * separados por espaço, vírgula ou ponto e vírgula; até 50.
 */
final class ListaInformada
{
    public const BYTES_DO_CSV = 1048576;
    public const LINHAS_DO_CSV = 5000;
    public const MAXIMO_INDIVIDUAL = 50;
    public const CSV_GRANDE = 'O arquivo passa de 1 MB.';
    public const CSV_LONGO = 'A lista passa de 5.000 linhas.';
    public const INDIVIDUAL_LONGO = 'Digite até 50 e-mails no envio individual.';
    private const CARACTERES_DO_INDIVIDUAL = 20000;

    /** @return list<DestinatarioDaCampanha> */
    public static function doCsv(string $texto): array
    {
        if (\strlen($texto) > self::BYTES_DO_CSV) {
            throw new CampanhaRecusada(self::CSV_GRANDE);
        }
        $linhas = array_values(array_filter(preg_split('/\r?\n/', $texto) ?: [], static fn (string $l): bool => trim($l) !== ''));
        if (\count($linhas) > self::LINHAS_DO_CSV) {
            throw new CampanhaRecusada(self::CSV_LONGO);
        }

        return array_values(array_filter(array_map(self::linhaDoCsv(...), $linhas), static fn (DestinatarioDaCampanha $d): bool => $d->valido()));
    }

    /** @return list<DestinatarioDaCampanha> */
    public static function doIndividual(string $texto): array
    {
        $partes = preg_split('/[\s,;]+/', mb_substr($texto, 0, self::CARACTERES_DO_INDIVIDUAL)) ?: [];
        $lista = array_values(array_filter(array_map(static fn (string $p): DestinatarioDaCampanha => new DestinatarioDaCampanha($p), $partes), static fn (DestinatarioDaCampanha $d): bool => $d->valido()));
        if (\count(array_unique(array_map(static fn (DestinatarioDaCampanha $d): string => $d->email, $lista))) > self::MAXIMO_INDIVIDUAL) {
            throw new CampanhaRecusada(self::INDIVIDUAL_LONGO);
        }

        return $lista;
    }

    private static function linhaDoCsv(string $linha): DestinatarioDaCampanha
    {
        $colunas = preg_split('/[;,\t]/', $linha) ?: [];
        $email = '';
        $nome = '';
        foreach ($colunas as $coluna) {
            if ($email === '' && str_contains($coluna, '@')) {
                $email = $coluna;
            } elseif ($nome === '' && $coluna !== '' && !str_contains($coluna, '@')) {
                $nome = str_replace('"', '', $coluna);
            }
        }

        return new DestinatarioDaCampanha($email, $nome);
    }
}
