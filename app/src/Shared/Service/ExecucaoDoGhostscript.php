<?php

declare(strict_types=1);

namespace App\Shared\Service;

use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use Symfony\Component\Process\Process;

/**
 * Roda o Ghostscript com um `TMPDIR` só dele, privado, e apaga o que ele deixar lá (D28, DT-7).
 *
 * O diretório por execução é uma {@see AreaTemporariaPrivada} — a mesma peça que o export da E2.6C
 * usa para materializar as imagens.
 *
 * O gs escreve temporários próprios (`gs_*`) enquanto processa — pedaços do documento. Sem isto
 * eles nascem no `/tmp` do container, com o modo do umask, e ficam para trás quando o processo é
 * morto por timeout: foi o que a revisão da E2.6A encontrou no container, arquivos `gs_*` órfãos com
 * fragmentos de dicionário do `pdfwrite`. Aqui eles nascem dentro do diretório privado do processo,
 * num subdiretório por execução, que sai no `finally`.
 *
 * O que NÃO está coberto: o PHP morto (Fatal, SIGKILL) não roda `finally`. Nesse caso sobra um
 * diretório `0700` dentro do temporário privado — invisível para outros usuários, limpo quando o
 * container é recriado.
 */
final class ExecucaoDoGhostscript
{
    /** Onde os subdiretórios por execução moram, dentro do temporário privado do processo. */
    public const FINALIDADE_DO_DIRETORIO_PRIVADO = 'gs';

    /**
     * @param list<string> $comando o binário e os argumentos, já montados
     *
     * @throws FalhaNoTemporario  quando não dá para preparar o diretório privado do gs
     * @throws \RuntimeException  timeout (`ProcessTimedOutException`) ou morte por sinal
     */
    public static function rodar(array $comando, float $timeoutSegundos): Process
    {
        $area = AreaTemporariaPrivada::criar(self::FINALIDADE_DO_DIRETORIO_PRIVADO);
        $meu  = $area->caminho();

        try {
            // TMPDIR é o que o gs lê no Unix; TMP e TEMP entram porque a ordem varia por build.
            $processo = new Process($comando, null, ['TMPDIR' => $meu, 'TMP' => $meu, 'TEMP' => $meu]);
            $processo->setTimeout($timeoutSegundos);
            $processo->run();

            return $processo;
        } finally {
            $area->liberar();
        }
    }
}
