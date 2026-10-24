<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Diz se um arquivo enviado à pasta já chega assinado digitalmente, para o selo "Assinado" nascer
 * sozinho no upload (`OrigemDaAssinatura::Digital`). Sem biblioteca: não VALIDA a assinatura (cadeia,
 * revogação, integridade), só reconhece que ela está lá.
 *
 * Dois sinais:
 *  - extensão `.p7s`/`.p7m` (CAdES destacado ou envelopado): o arquivo inteiro é a assinatura;
 *  - PDF com dicionário de assinatura (PAdES, ICP-Brasil, gov.br): `/Type /Sig` ou `/FT /Sig`, JUNTO
 *    com `/ByteRange [` e um `/SubFilter` de assinatura conhecido. Os três juntos, porque cada um
 *    sozinho aparece em PDF sem assinatura (um campo de assinatura vazio tem `/FT /Sig`; um texto
 *    pode citar "/ByteRange").
 *
 * Lê só o começo e o fim do arquivo (`LIMITE_POR_PONTA` em cada): o assinador grava a assinatura numa
 * atualização incremental, no fim; o começo cobre o PDF gerado já assinado por quem o escreveu.
 * O meio de um PDF grande não é lido: um arquivo assinado e depois reeditado várias vezes pode
 * escapar, e aí a marcação manual do ⋮ resolve.
 */
final class DetectorDeAssinaturaDigital
{
    /** Bytes lidos em cada ponta. A `/Contents` de uma assinatura com carimbo e LTV passa de 100 KB. */
    public const LIMITE_POR_PONTA = 1024 * 1024;

    private const EXTENSOES_ASSINADAS = ['p7s', 'p7m'];

    /** O nome termina num delimitador PDF (espaço, `/`, `<`, `>`, `[`, `]`, `(`, `)`, `{`, `}`, `%`) ou no fim. */
    private const FIM_DE_NOME = '(?![^\s\/<>\[\](){}%])';

    private const SUBFILTROS = [
        'adbe\.pkcs7\.detached',
        'ETSI\.CAdES\.detached',
        'adbe\.pkcs7\.sha1',
        'ETSI\.RFC3161',
    ];

    /**
     * O arquivo local (o temporário do upload, antes de ir para o armazenamento) chega assinado?
     * Arquivo ilegível responde `false`: o selo é conveniência e nunca derruba o upload.
     */
    public function arquivoAssinado(string $caminhoLocal, string $nomeOriginal, string $mimeType): bool
    {
        if ($this->extensaoAssinada($nomeOriginal)) {
            return true;
        }

        if ($mimeType !== 'application/pdf') {
            return false;
        }

        $trecho = $this->pontasDoArquivo($caminhoLocal);

        return $trecho !== null && $this->pdfAssinado($trecho);
    }

    public function extensaoAssinada(string $nomeArquivo): bool
    {
        return \in_array(strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION)), self::EXTENSOES_ASSINADAS, true);
    }

    /** Puro: os bytes de um PDF (ou de trechos dele) têm um dicionário de assinatura? */
    public function pdfAssinado(string $bytes): bool
    {
        $fim = self::FIM_DE_NOME;

        return preg_match('/\/(?:Type|FT)\s*\/Sig' . $fim . '/', $bytes) === 1
            && preg_match('/\/ByteRange\s*\[/', $bytes) === 1
            && preg_match('/\/SubFilter\s*\/(?:' . implode('|', self::SUBFILTROS) . ')' . $fim . '/', $bytes) === 1;
    }

    /**
     * O conteúdo de um stream aberto (o `abrir()` do armazenamento, na varredura do acervo) tem um
     * dicionário de assinatura? Lê só as pontas: com seek quando o stream deixa, senão em sequência,
     * guardando o começo e uma janela do fim. Null = a leitura falhou no meio (quem chama conta o erro).
     * Quem abriu o stream é quem fecha.
     *
     * @param resource $stream
     */
    public function streamAssinado(mixed $stream): ?bool
    {
        $trecho = $this->pontasDoStream($stream);

        return $trecho === null ? null : $this->pdfAssinado($trecho);
    }

    /** Começo e fim do arquivo local; null se não der para ler. */
    private function pontasDoArquivo(string $caminho): ?string
    {
        $arquivo = @fopen($caminho, 'rb');
        if ($arquivo === false) {
            return null;
        }

        try {
            return $this->pontasDoStream($arquivo);
        } finally {
            fclose($arquivo);
        }
    }

    /** @param resource $stream */
    private function pontasDoStream(mixed $stream): ?string
    {
        $limite  = self::LIMITE_POR_PONTA;
        $tamanho = (fstat($stream) ?: [])['size'] ?? null;

        if (\is_int($tamanho) && $tamanho > 2 * $limite && stream_get_meta_data($stream)['seekable']) {
            $inicio = stream_get_contents($stream, $limite, 0);
            $fim    = stream_get_contents($stream, $limite, $tamanho - $limite);

            // Uma quebra entre as pontas: um marcador não se forma emendando o fim de uma no começo da outra.
            return \is_string($inicio) && \is_string($fim) ? $inicio . "\n" . $fim : null;
        }

        // Sem seek (stream remoto) ou arquivo que cabe nas duas pontas: em sequência, guardando o
        // começo inteiro e uma janela deslizante do fim. A memória fica em ~3 pontas, nunca no arquivo.
        $inicio = '';
        $fim    = '';
        $lidos  = 0;
        while (!feof($stream)) {
            $bloco = @fread($stream, 64 * 1024);
            if ($bloco === false) {
                return null;
            }
            if ($bloco === '') {
                break;
            }

            $lidos += \strlen($bloco);
            if (\strlen($inicio) < $limite) {
                $falta   = $limite - \strlen($inicio);
                $inicio .= substr($bloco, 0, $falta);
                $bloco   = (string) substr($bloco, $falta);
            }
            if ($bloco !== '') {
                $fim = substr($fim . $bloco, -$limite);
            }
        }

        return $fim === '' ? $inicio : $inicio . ($lidos > 2 * $limite ? "\n" : '') . $fim;
    }
}
