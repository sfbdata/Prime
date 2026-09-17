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
    public const BINARIO_REAL = '/usr/bin/gs';

    public static function disponivel(): bool
    {
        return is_executable(self::BINARIO_REAL);
    }

    /** Um PDF válido de `$paginas` páginas, gerado pelo Ghostscript real. */
    public static function pdfReal(string $destino, int $paginas): void
    {
        $processo = new Process([
            self::BINARIO_REAL, '-q', '-dBATCH', '-dNOPAUSE', '-dSAFER', '-sDEVICE=pdfwrite',
            '-sOutputFile=' . $destino,
            '-c', sprintf('/Helvetica findfont 20 scalefont setfont %d { 72 720 moveto (pagina) show showpage } repeat', $paginas),
        ]);
        $processo->mustRun();
    }

    /**
     * Escreve o script falso em `$diretorio` e devolve o caminho dele.
     *
     * @param string $aoComprimir trecho de shell executado quando o dispositivo é `pdfwrite`
     */
    public static function falso(string $diretorio, string $aoComprimir): string
    {
        $caminho = $diretorio . '/gs-falso-' . bin2hex(random_bytes(4));
        $real    = self::BINARIO_REAL;
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
