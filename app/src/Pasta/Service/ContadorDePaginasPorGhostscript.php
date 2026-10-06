<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Shared\Service\ExecucaoDoGhostscript;
use Psr\Log\LoggerInterface;

/**
 * Conta as páginas de um PDF com o Ghostscript, sem renderizar nada (D1).
 *
 * ## Por que assim
 *
 *  - `-dNODISPLAY`: nenhum dispositivo de saída — o gs só interpreta o PDF; é o que torna a
 *    contagem barata (centenas de ms, não segundos);
 *  - `-dSAFER` + `--permit-file-read=<arquivo>`: o interpretador PostScript não pode abrir arquivo
 *    nenhum além do próprio PDF (o `(…) (r) file` do script precisa dessa permissão explícita, e
 *    só dela). Um PDF malicioso não lê `/etc/passwd` nem grava nada;
 *  - `runpdfbegin pdfpagecount`: a contagem que o próprio gs faz — não um `grep /Type /Page`, que
 *    erra em PDF com objetos em streams comprimidos (a maioria dos PDFs assinados do PJe);
 *  - o processo roda pela {@see ExecucaoDoGhostscript}: `TMPDIR` privado e temporários do gs limpos;
 *  - timeout curto e falha → NULL: a contagem é opcional (nunca derruba um upload).
 *
 * O caminho vai escapado para dentro de uma string PostScript: `(`, `)` e `\` são os três
 * caracteres que fechariam ou quebrariam a string.
 */
final class ContadorDePaginasPorGhostscript implements ContadorDePaginasDePdf
{
    /** Segundos. Contar não renderiza; um PDF de centenas de páginas conta em poucos segundos. */
    public const TIMEOUT_PADRAO = 20.0;

    public function __construct(
        private readonly string $ghostscriptBin,
        private readonly LoggerInterface $logger,
        private readonly float $timeoutSegundos = self::TIMEOUT_PADRAO,
    ) {
    }

    public function contar(string $caminhoLocal): ?int
    {
        if (!is_file($caminhoLocal) || !is_readable($caminhoLocal)) {
            return null;
        }

        try {
            $processo = ExecucaoDoGhostscript::rodar([
                $this->ghostscriptBin,
                '-q',
                '-dNODISPLAY',
                '-dSAFER',
                '-dNOPAUSE',
                '-dBATCH',
                '--permit-file-read=' . $caminhoLocal,
                '-c',
                sprintf('(%s) (r) file runpdfbegin pdfpagecount = quit', self::escaparParaPostScript($caminhoLocal)),
            ], $this->timeoutSegundos);
        } catch (\Throwable $e) {
            // timeout, sinal, binário que não sobe ou temporário indisponível: sem contagem
            $this->logger->warning('Contagem de páginas do PDF não concluída; fica NULL.', [
                'arquivo' => basename($caminhoLocal),
                'erro'    => $e->getMessage(),
            ]);

            return null;
        }

        if (!$processo->isSuccessful()) {
            $this->logger->warning('Ghostscript não contou as páginas do PDF; fica NULL.', [
                'arquivo' => basename($caminhoLocal),
                'saida'   => mb_substr(trim($processo->getErrorOutput() . "\n" . $processo->getOutput()), 0, 500),
            ]);

            return null;
        }

        return self::ultimoInteiroPositivo($processo->getOutput());
    }

    /**
     * O `=` do script imprime só o número, numa linha. Se o gs emitir aviso antes (fonte
     * substituída, por exemplo), a contagem continua sendo a ÚLTIMA linha só com dígitos. Zero não
     * é contagem — é um PDF sem página, que fica NULL como qualquer outro que não se contou.
     */
    public static function ultimoInteiroPositivo(string $saida): ?int
    {
        if (preg_match_all('/^\s*(\d+)\s*$/m', $saida, $encontros) === 0) {
            return null;
        }

        $ultimo = (int) end($encontros[1]);

        return $ultimo >= 1 ? $ultimo : null;
    }

    private static function escaparParaPostScript(string $texto): string
    {
        return strtr($texto, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}
