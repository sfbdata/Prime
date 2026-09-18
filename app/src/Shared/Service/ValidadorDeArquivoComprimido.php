<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * A validação da saída do compressor (D27), medida contra o Ghostscript 10.05 do container:
 *
 *  - lixo e PDF corrompido no meio saem com código 1 quando relidos com `-dPDFSTOPONERROR`;
 *  - um PDF cuja escrita foi interrompida sai com **código 0 e nenhuma página processada**. Por
 *    isso o código de saída não basta: a releitura precisa anunciar as mesmas páginas que a
 *    compressão leu do original ("Processing pages 1 through N.", linha que o `-q` suprime);
 *  - o GD abre JPEG truncado sem reclamar (`gd.jpeg_ignore_warning`), completando de cinza. Por isso
 *    a imagem precisa terminar com o marcador de fim do formato, além de decodificar.
 *
 * Lê os arquivos por `fopen`, nunca inteiros: a saída de um PDF grande não vai para a memória.
 */
final class ValidadorDeArquivoComprimido implements ValidadorDeArquivoComprimidoInterface
{
    /** Quanto do fim do PDF é lido atrás do `%%EOF` (o padrão tolera lixo nos últimos 1024 bytes). */
    private const BYTES_DO_FIM_DO_PDF = 2048;

    private const FIM_DO_JPEG = "\xFF\xD9";
    private const FIM_DO_PNG  = "IEND\xAE\x42\x60\x82";

    public function __construct(
        private readonly string $ghostscriptBin,
        private readonly int $timeoutSegundos = CompressorArquivo::TIMEOUT_PDF_PADRAO,
    ) {
    }

    public function pdfValido(string $caminho, int $paginasEsperadas, ?float $timeoutSegundos = null): bool
    {
        if ($paginasEsperadas < 1
            || $this->inicio($caminho, 5) !== '%PDF-'
            || !str_contains($this->fim($caminho, self::BYTES_DO_FIM_DO_PDF), '%%EOF')
        ) {
            return false;
        }

        try {
            $processo = ExecucaoDoGhostscript::rodar([
                $this->ghostscriptBin,
                '-dBATCH',
                '-dNOPAUSE',
                '-dSAFER',
                '-dPDFSTOPONERROR',
                '-sDEVICE=nullpage',
                $caminho,
            ], $timeoutSegundos ?? (float) $this->timeoutSegundos);
        } catch (\RuntimeException) {
            // timeout, sinal, binário que não sobe ou temporário indisponível: na dúvida, não
            return false;
        }

        return $processo->isSuccessful()
            && self::paginasProcessadas($processo->getOutput()) === $paginasEsperadas;
    }

    public function imagemValida(string $caminho, string $mimeType, int $largura, int $altura): bool
    {
        [$fimDoFormato, $decodificar] = match ($mimeType) {
            'image/jpeg' => [self::FIM_DO_JPEG, 'imagecreatefromjpeg'],
            'image/png'  => [self::FIM_DO_PNG, 'imagecreatefrompng'],
            default      => [null, null],
        };

        if ($fimDoFormato === null || $this->fim($caminho, \strlen($fimDoFormato)) !== $fimDoFormato) {
            return false;
        }

        // A mesma guarda do compressor: decodificar sem caber na memória é Fatal error, e aqui ele
        // cairia DEPOIS de a saída já estar pronta — matando o worker no fim do caminho feliz.
        if (!OrcamentoDeMemoriaDeImagem::cabe($largura, $altura)) {
            return false;
        }

        $imagem = @$decodificar($caminho);
        if ($imagem === false) {
            return false;
        }

        $dimensoesIguais = imagesx($imagem) === $largura && imagesy($imagem) === $altura;
        imagedestroy($imagem);

        return $dimensoesIguais;
    }

    /**
     * As páginas que o Ghostscript anunciou ter lido ("Processing pages 1 through N."). Null quando a
     * linha não aparece, aparece com contagens diferentes ou anuncia zero — nada disso prova páginas.
     */
    public static function paginasProcessadas(string $saidaDoGhostscript): ?int
    {
        preg_match_all('/^Processing pages 1 through (\d+)\.\s*$/m', $saidaDoGhostscript, $encontros);

        $contagens = array_values(array_unique(array_map('intval', $encontros[1])));
        if (\count($contagens) !== 1 || $contagens[0] < 1) {
            return null;
        }

        return $contagens[0];
    }

    private function inicio(string $caminho, int $bytes): string
    {
        $arquivo = @fopen($caminho, 'rb');
        if ($arquivo === false) {
            return '';
        }

        try {
            return (string) fread($arquivo, $bytes);
        } finally {
            fclose($arquivo);
        }
    }

    private function fim(string $caminho, int $bytes): string
    {
        $arquivo = @fopen($caminho, 'rb');
        if ($arquivo === false) {
            return '';
        }

        try {
            $tamanho = fstat($arquivo)['size'] ?? 0;
            if ($tamanho < $bytes || fseek($arquivo, -$bytes, \SEEK_END) !== 0) {
                fseek($arquivo, 0);
            }

            return (string) stream_get_contents($arquivo);
        } finally {
            fclose($arquivo);
        }
    }
}
