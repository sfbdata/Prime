<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Psr\Log\LoggerInterface;

/**
 * Comprime JPEG e PNG (GD) e PDF (Ghostscript) no lugar — best-effort: nunca lança e nunca destrói o
 * arquivo que recebeu (INV-7).
 *
 * ## O que a E2.6A mudou (D4, D27, D28)
 *
 *  - a versão comprimida só substitui o original depois do {@see ValidadorDeArquivoComprimidoInterface}:
 *    "menor" nunca bastou — um Ghostscript interrompido deixa um PDF menor, com código zero e vazio;
 *  - o temporário `.compress_*` nasce com nome conhecido ANTES de qualquer processo e sai em `finally`:
 *    timeout e processo morto por sinal lançam antes da limpeza que havia, e o parcial sobrava ao lado
 *    do arquivo — dentro do volume de uploads, enquanto o chamador passava o persistido;
 *  - `rename()` e `filesize()` não emitem aviso: em debug, um aviso vira exceção e escapava do
 *    "nunca lança";
 *  - o timeout do Ghostscript é injetável, para o modo de falha poder ser provado;
 *  - imagem que não cabe na memória disponível não é decodificada ({@see OrcamentoDeMemoriaDeImagem}):
 *    estourar o `memory_limit` é Fatal error, que nenhum `catch` pega e nenhum `finally` limpa —
 *    medido, um PNG de poucos KB com 7000x7000 matava o worker;
 *  - o gs roda com `TMPDIR` próprio ({@see ExecucaoDoGhostscript}) e com `-dPDFSTOPONERROR`: os
 *    temporários dele saem junto, e um PDF danificado não é "reparado" por cima do original.
 *
 * Quem chama com arquivo persistido é o serviço `CompressaoDeArquivoArmazenado`, sobre uma cópia
 * gravável: é nela que o temporário e a troca acontecem, fora do volume.
 */
final class CompressorArquivo implements CompressorArquivoInterface
{
    /**
     * Orçamento (s) padrão para TODO o PDF: comprimir mais reler na validação.
     *
     * É um orçamento, não um timeout por processo. O nginx corta a requisição em 120 s
     * (`fastcgi_read_timeout`, `nginx/conf.d/nginx.prod.conf`), então dois processos de 120 s
     * entregariam 504 ao usuário com o PHP ainda trabalhando. Com 90 s sobram 30 s para o resto da
     * requisição, e a validação recebe o que sobrar da compressão.
     */
    public const TIMEOUT_PDF_PADRAO = 90;

    /** Piso do que a validação ganha quando a compressão consumiu quase tudo. */
    private const PRAZO_MINIMO_DE_VALIDACAO = 1.0;

    private const QUALIDADE_JPEG = 75;
    private const NIVEL_PNG      = 9;

    /** Teto absoluto de pixels, além do que a memória disponível já recusa. */
    private const MAX_PIXELS_IMAGEM = 50_000_000;

    private const BLOCO_DE_LEITURA       = 1024 * 1024;
    private const SOBREPOSICAO_DE_LEITURA = 32; // maior que qualquer marcador procurado

    private readonly ValidadorDeArquivoComprimidoInterface $validador;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $ghostscriptBin,
        ?ValidadorDeArquivoComprimidoInterface $validador = null,
        private readonly int $timeoutPdfSegundos = self::TIMEOUT_PDF_PADRAO,
    ) {
        if ($timeoutPdfSegundos < 1) {
            // `setTimeout(0)` no Symfony significa SEM limite: o processo poderia ficar preso para
            // sempre, e o "melhor esforço" viraria worker travado.
            throw new \InvalidArgumentException('O orçamento de tempo do Ghostscript tem de ser de pelo menos 1 segundo.');
        }

        $this->validador = $validador ?? new ValidadorDeArquivoComprimido($ghostscriptBin, $timeoutPdfSegundos);
    }

    /** A mesma lista do `match` de {@see comprimir()} — os dois não podem divergir. */
    public function trata(string $mimeType): bool
    {
        return \in_array($mimeType, ['image/jpeg', 'image/png', 'application/pdf'], true);
    }

    public function comprimir(string $caminhoCompleto, string $mimeType): ResultadoCompressao
    {
        clearstatcache(true, $caminhoCompleto);
        if (!is_file($caminhoCompleto)) {
            return ResultadoCompressao::naoComprimido(0);
        }

        $tamanhoOriginal = @filesize($caminhoCompleto);
        if ($tamanhoOriginal === false) {
            $this->logger->warning('Não foi possível medir o arquivo; compressão não aplicada.', [
                'caminho' => $caminhoCompleto,
            ]);

            return ResultadoCompressao::naoComprimido(0);
        }

        $eraAssinado = $mimeType === 'application/pdf' && $this->pdfEstaAssinado($caminhoCompleto);
        $temporario  = \dirname($caminhoCompleto) . '/.compress_' . bin2hex(random_bytes(8));
        $prazoFinal  = microtime(true) + $this->timeoutPdfSegundos;

        try {
            $esperado = match ($mimeType) {
                'image/jpeg', 'image/png' => $this->comprimirImagem($caminhoCompleto, $temporario, $mimeType),
                'application/pdf'         => $this->comprimirPdf($caminhoCompleto, $temporario, $prazoFinal),
                default                   => null,
            };

            if ($esperado === null) {
                return ResultadoCompressao::naoComprimido($tamanhoOriginal, $eraAssinado);
            }

            clearstatcache(true, $temporario);
            $tamanhoFinal = @filesize($temporario);

            // Só vale a pena trocar se o resultado for menor e não-vazio — mas isso não prova nada.
            if ($tamanhoFinal === false || $tamanhoFinal <= 0 || $tamanhoFinal >= $tamanhoOriginal) {
                return ResultadoCompressao::naoComprimido($tamanhoOriginal, $eraAssinado);
            }

            if (!$this->saidaValida($temporario, $mimeType, $esperado, $prazoFinal)) {
                $this->logger->warning('A versão comprimida não passou na validação; mantendo original.', [
                    'caminho'  => $caminhoCompleto,
                    'mimeType' => $mimeType,
                ]);

                return ResultadoCompressao::naoComprimido($tamanhoOriginal, $eraAssinado);
            }

            if (!@rename($temporario, $caminhoCompleto)) {
                $this->logger->warning('Não foi possível trocar o arquivo pela versão comprimida; mantendo original.', [
                    'caminho' => $caminhoCompleto,
                ]);

                return ResultadoCompressao::naoComprimido($tamanhoOriginal, $eraAssinado);
            }

            return new ResultadoCompressao($tamanhoOriginal, $tamanhoFinal, true, $eraAssinado);
        } catch (\Throwable $e) {
            $this->logger->warning('Falha ao comprimir arquivo; mantendo original.', [
                'caminho'  => $caminhoCompleto,
                'mimeType' => $mimeType,
                'erro'     => $e->getMessage(),
            ]);

            return ResultadoCompressao::naoComprimido($tamanhoOriginal, $eraAssinado);
        } finally {
            clearstatcache(true, $temporario);
            if (is_file($temporario)) {
                @unlink($temporario);
            }
        }
    }

    /**
     * Em blocos, nunca o arquivo inteiro: o upload chega a 65 MB (`upload_max_filesize`) e o
     * `memory_limit` do container é 128 MB — um `file_get_contents()` aqui competia com a memória
     * da requisição, e estourar seria Fatal error dentro de um método que promete não lançar.
     *
     * A sobreposição entre blocos é o que impede um marcador de ser perdido na emenda.
     */
    public function pdfEstaAssinado(string $caminhoCompleto): bool
    {
        if (!is_file($caminhoCompleto)) {
            return false;
        }

        $arquivo = @fopen($caminhoCompleto, 'rb');
        if ($arquivo === false) {
            return false;
        }

        $temByteRange  = false;
        $temAssinatura = false;
        $sobra         = '';

        try {
            while (!feof($arquivo)) {
                $bloco = @fread($arquivo, self::BLOCO_DE_LEITURA);
                if ($bloco === false || $bloco === '') {
                    break;
                }

                $janela        = $sobra . $bloco;
                $temByteRange  = $temByteRange || str_contains($janela, '/ByteRange');
                $temAssinatura = $temAssinatura
                    || str_contains($janela, '/Sig')
                    || str_contains($janela, '/Adobe.PPKLite');

                if ($temByteRange && $temAssinatura) {
                    return true;
                }

                $sobra = substr($janela, -self::SOBREPOSICAO_DE_LEITURA);
            }
        } finally {
            fclose($arquivo);
        }

        return $temByteRange && $temAssinatura;
    }

    /** @param int|array{int, int} $esperado as páginas do PDF, ou a largura e a altura da imagem */
    private function saidaValida(string $temporario, string $mimeType, int|array $esperado, float $prazoFinal): bool
    {
        return \is_int($esperado)
            ? $this->validador->pdfValido($temporario, $esperado, max(self::PRAZO_MINIMO_DE_VALIDACAO, $prazoFinal - microtime(true)))
            : $this->validador->imagemValida($temporario, $mimeType, $esperado[0], $esperado[1]);
    }

    /**
     * Reescreve a imagem em `$temporario`.
     *
     * @return array{int, int}|null largura e altura do original, ou null quando não há o que comparar
     */
    private function comprimirImagem(string $origem, string $temporario, string $mimeType): ?array
    {
        $info = @getimagesize($origem);
        if ($info === false) {
            $this->logger->warning('Não foi possível ler as dimensões da imagem; mantendo original.', [
                'caminho' => $origem,
            ]);

            return null;
        }

        if (($info[0] * $info[1]) > self::MAX_PIXELS_IMAGEM
            || !OrcamentoDeMemoriaDeImagem::cabe($info[0], $info[1])
        ) {
            // Decodificar sem caber é Fatal error: o `catch` não roda, o `finally` não limpa e o
            // worker morre no meio do upload. Comprimir é opcional; não morrer, não.
            $this->logger->warning('Imagem grande demais para a memória disponível; mantendo original.', [
                'caminho' => $origem,
                'largura' => $info[0],
                'altura'  => $info[1],
            ]);

            return null;
        }

        $imagem = $mimeType === 'image/jpeg' ? @imagecreatefromjpeg($origem) : @imagecreatefrompng($origem);
        if ($imagem === false) {
            $this->logger->warning('Não foi possível decodificar a imagem; mantendo original.', [
                'caminho'  => $origem,
                'mimeType' => $mimeType,
            ]);

            return null;
        }

        if ($mimeType === 'image/png') {
            // Preserva transparência ao reescrever.
            imagealphablending($imagem, false);
            imagesavealpha($imagem, true);
        }

        $escreveu = $mimeType === 'image/jpeg'
            ? @imagejpeg($imagem, $temporario, self::QUALIDADE_JPEG)
            : @imagepng($imagem, $temporario, self::NIVEL_PNG);
        imagedestroy($imagem);

        if (!$escreveu) {
            $this->logger->warning('Não foi possível gravar a imagem comprimida; mantendo original.', [
                'caminho'  => $origem,
                'mimeType' => $mimeType,
            ]);

            return null;
        }

        return [$info[0], $info[1]];
    }

    /**
     * Comprime o PDF em `$temporario`.
     *
     * Sem `-dQUIET`: é a linha "Processing pages 1 through N." que diz quantas páginas o Ghostscript
     * leu do original — a contagem que a releitura da saída tem de repetir. Timeout e sinal lançam
     * (a limpeza está no `finally` de quem chama).
     *
     * Com `-dPDFSTOPONERROR`: um PDF danificado é "reparado" pelo gs em silêncio, e a saída
     * reparada passaria na validação e substituiria o original — o arquivo que o cliente enviou
     * seria trocado por uma reconstrução, sem registro. Melhor não comprimir.
     *
     * @return int|null as páginas lidas do original, ou null quando não há saída confiável
     */
    private function comprimirPdf(string $origem, string $temporario, float $prazoFinal): ?int
    {
        $processo = ExecucaoDoGhostscript::rodar([
            $this->ghostscriptBin,
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=/ebook',
            '-dDetectDuplicateImages=true',
            '-dPDFSTOPONERROR',
            '-dNOPAUSE',
            '-dBATCH',
            '-sOutputFile=' . $temporario,
            $origem,
        ], max(self::PRAZO_MINIMO_DE_VALIDACAO, $prazoFinal - microtime(true)));

        if (!$processo->isSuccessful() || !is_file($temporario)) {
            $this->logger->warning('Ghostscript não comprimiu o PDF; mantendo original.', [
                'caminho' => $origem,
                'saida'   => $processo->getErrorOutput(),
            ]);

            return null;
        }

        $paginas = ValidadorDeArquivoComprimido::paginasProcessadas($processo->getOutput());
        if ($paginas === null) {
            $this->logger->warning('Ghostscript não informou as páginas lidas; mantendo original.', [
                'caminho' => $origem,
            ]);
        }

        return $paginas;
    }
}
