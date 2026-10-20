<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Nome de arquivo para exibir e para o cabeçalho de download.
 *
 * O `Content-Disposition` do Symfony (`HeaderUtils::makeDisposition`) lança exceção com "/" ou "\" no nome:
 * um documento renomeado para "Contrato 01/2026" deixava o visualizar e o baixar em erro 500. Aqui a barra e
 * a contrabarra viram "-" e os caracteres de controle saem. Serve ao renomear (o nome novo já é gravado limpo)
 * e à entrega (nome antigo já gravado com barra, ou vindo do Drive, continua baixando).
 */
final class NomeSeguroDeArquivo
{
    private function __construct()
    {
    }

    /** Pode devolver texto vazio: quem chama decide o que fazer com ele. */
    public static function sanear(string $nome): string
    {
        $semBarra = str_replace(['/', '\\'], '-', $nome);
        $semControle = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $semBarra);

        return trim($semControle);
    }

    /** Para o download: nunca vazio. */
    public static function paraDownload(string $nome): string
    {
        $limpo = self::sanear($nome);

        return $limpo !== '' ? $limpo : 'arquivo';
    }
}
