<?php

declare(strict_types=1);

namespace App\Shared\Service;

use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
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

    /**
     * Como {@see rodar()}, mas lê a saída padrão em fluxo e para o gs quando ela passa do teto.
     *
     * Serve quando a saída depende do documento e não tem tamanho previsível: nada dela fica inteiro
     * na memória e o gs não continua trabalhando depois que a saída deixou de interessar. A saída
     * de erro é descartada pelo descritor, sem acumular. A leitura direta é intencional: o iterador
     * do Process pode acumular um produtor rápido em UnixPipes antes de entregar o primeiro pedaço.
     *
     * Ao voltar, o processo já terminou: sozinho, parado no teto ou parado no tempo. Nenhum dos três
     * deixa filho vivo: o comando é executado diretamente e seu PID é encerrado e recolhido antes do retorno.
     *
     * @param list<string> $comando o binário e os argumentos, já montados
     *
     * @throws FalhaNoTemporario  quando não dá para preparar o diretório privado do gs
     * @throws \RuntimeException  quando o processo nem chega a começar
     */
    public static function rodarComTeto(array $comando, float $tempo, int $tetoDeBytes): SaidaDoGhostscript
    {
        $area = AreaTemporariaPrivada::criar(self::FINALIDADE_DO_DIRETORIO_PRIVADO);
        $meu  = $area->caminho();

        try {
            // PHP 8.3 pode recusar proc_open antes de criar o processo quando o binário não existe.
            if (str_contains($comando[0], '/') && !file_exists($comando[0])) {
                return new SaidaDoGhostscript('', 127, false, false);
            }
            [$processo, $canal] = self::abrirComSaidaLimitada($comando, $meu);
            try {
                $saida = self::lerAteOTeto($processo, $canal, $tempo, $tetoDeBytes);
            } finally {
                $codigo = self::encerrar($processo, $canal);
            }

            return new SaidaDoGhostscript($saida->saida, $saida->codigo ?? $codigo, $saida->tempoEstourado, $saida->tetoEstourado);
        } finally {
            $area->liberar();
        }
    }

    /**
     * @param list<string> $comando
     *
     * @return array{resource, resource}
     */
    private static function abrirComSaidaLimitada(array $comando, string $temporario): array
    {
        $ambiente = array_merge(getenv(), ['TMPDIR' => $temporario, 'TMP' => $temporario, 'TEMP' => $temporario]);
        $descritores = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        // Array de argumentos: execução direta, sem shell nem interpolação do caminho do PDF.
        $processo = @proc_open($comando, $descritores, $canais, null, $ambiente);
        if (!\is_resource($processo)) {
            throw new \RuntimeException('Não foi possível iniciar o Ghostscript.');
        }
        stream_set_blocking($canais[1], false);

        return [$processo, $canais[1]];
    }

    /**
     * @param resource $processo
     * @param resource $canal
     */
    private static function lerAteOTeto(mixed $processo, mixed $canal, float $tempo, int $teto): SaidaDoGhostscript
    {
        $saida = '';
        $inicio = hrtime(true);
        while (true) {
            if ((hrtime(true) - $inicio) / 1e9 >= $tempo) {
                return new SaidaDoGhostscript($saida, null, true, false);
            }
            $pedaco = fread($canal, 8192);
            if ($pedaco === false) {
                throw new \RuntimeException('Não foi possível ler a saída do Ghostscript.');
            }
            $saida .= $pedaco;
            if (\strlen($saida) > $teto) {
                return new SaidaDoGhostscript(substr($saida, 0, $teto), null, false, true);
            }
            if (feof($canal)) {
                $estado = proc_get_status($processo);
                if (!$estado['running']) {
                    $codigo = $estado['signaled'] ? 128 + $estado['termsig'] : $estado['exitcode'];

                    return new SaidaDoGhostscript($saida, $codigo >= 0 ? $codigo : null, false, false);
                }
            }
            if ($pedaco === '') {
                usleep(1000);
            }
        }
    }

    /**
     * @param resource $processo
     * @param resource $canal
     */
    private static function encerrar(mixed $processo, mixed $canal): ?int
    {
        $estado = proc_get_status($processo);
        $parado = $estado['running'] && proc_terminate($processo, 9);
        fclose($canal);
        $codigo = proc_close($processo);

        return $parado ? 137 : ($codigo >= 0 ? $codigo : null);
    }
}
