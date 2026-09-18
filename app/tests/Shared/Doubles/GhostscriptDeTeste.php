<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use Symfony\Component\Process\Process;

/**
 * Ghostscript para os modos de falha do compressor (D4, D27).
 *
 *  - {@see pdfReal()} gera um PDF de verdade, com o número de páginas pedido, pelo binário real;
 *  - {@see falso()} escreve um script que se passa pelo Ghostscript: na COMPRESSÃO (`pdfwrite`) faz o
 *    que o teste mandar; em qualquer outra chamada — a releitura de validação — delega ao binário
 *    real. Assim o teste controla a saída "comprimida" e a validação continua sendo a de verdade.
 *
 * No script, `$out` é o `-sOutputFile=` e `$in` é o último argumento (o arquivo de entrada).
 */
final class GhostscriptDeTeste
{
    /** O padrão da imagem; `GHOSTSCRIPT_BIN` manda, como no `%ghostscript_bin%` da aplicação. */
    public const BINARIO_REAL = '/usr/bin/gs';

    public static function binario(): string
    {
        $doAmbiente = getenv('GHOSTSCRIPT_BIN');

        return \is_string($doAmbiente) && $doAmbiente !== '' ? $doAmbiente : self::BINARIO_REAL;
    }

    public static function disponivel(): bool
    {
        return is_executable(self::binario());
    }

    /** Um PDF válido de `$paginas` páginas, gerado pelo Ghostscript real. */
    public static function pdfReal(string $destino, int $paginas): void
    {
        $processo = new Process([
            self::binario(), '-q', '-dBATCH', '-dNOPAUSE', '-dSAFER', '-sDEVICE=pdfwrite',
            '-sOutputFile=' . $destino,
            '-c', sprintf('/Helvetica findfont 20 scalefont setfont %d { 72 720 moveto (pagina) show showpage } repeat', $paginas),
        ]);
        $processo->mustRun();
    }

    /**
     * Um PDF válido que o Ghostscript reduz DE VERDADE: o mesmo PDF real, engordado depois do
     * `%%EOF` (o enchimento é comentário; o gs lê as mesmas páginas e devolve o arquivo limpo).
     *
     * Medido: 3 páginas com 4000 linhas de enchimento saem de ~55 KB para ~3,8 KB.
     */
    public static function pdfGordo(string $destino, int $paginas = 3, int $linhasDeEnchimento = 4000): string
    {
        self::pdfReal($destino, $paginas);
        file_put_contents($destino, str_repeat("% enchimento\n", $linhasDeEnchimento), \FILE_APPEND);

        return (string) file_get_contents($destino);
    }

    /**
     * Escreve o script falso em `$diretorio` e devolve o caminho dele.
     *
     * @param string $aoComprimir trecho de shell executado quando o dispositivo é `pdfwrite`
     */
    public static function falso(string $diretorio, string $aoComprimir): string
    {
        $caminho = $diretorio . '/gs-falso-' . bin2hex(random_bytes(4));
        $real    = self::binario();
        $script  = <<<SH
            #!/bin/sh
            out=""; device=""; in=""
            for a in "\$@"; do
              case "\$a" in
                -sOutputFile=*) out="\${a#-sOutputFile=}" ;;
                -sDEVICE=*) device="\${a#-sDEVICE=}" ;;
              esac
              in="\$a"
            done
            if [ "\$device" = "pdfwrite" ]; then
            {$aoComprimir}
            fi
            exec {$real} "\$@"

            SH;

        file_put_contents($caminho, $script);
        chmod($caminho, 0o700);

        return $caminho;
    }
}
