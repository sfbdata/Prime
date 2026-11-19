<?php

declare(strict_types=1);

namespace App\Shared\Texto;

use App\Shared\Armazenamento\AreaTemporariaPrivada;

/**
 * Tira o texto de um ODT (OpenDocument): `content.xml` do ZIP, só o que está dentro de `office:text`.
 *
 * Mesmas defesas de pacote do {@see ExtratorDeTextoDocx}: assinatura `PK`, entrada `mimetype` com o conteúdo exato,
 * no máximo {@see ExtratorDeTextoDocx::MAXIMO_DE_ENTRADAS} entradas, leitura limitada do `content.xml`, razão de
 * compressão máxima, recusa de `<!DOCTYPE`/`<!ENTITY` e `LIBXML_NONET`. O ZIP vive numa área temporária privada,
 * apagada no `finally`.
 */
final class LeitorDeTextoDoOdt
{
    private const MENSAGEM = 'O arquivo não é um ODT válido.';
    private const MIMETYPE = 'application/vnd.oasis.opendocument.text';
    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    private const MAXIMO_DE_ESPACOS = 1000;
    private const LIMITE_DE_LEITURA = 2_000_000;
    private const IGNORADOS = ['tracked-changes', 'sequence-decls', 'variable-decls', 'user-field-decls', 'dde-connection-decls'];

    /** @throws ArquivoSemTextoException */
    public function ler(string $bytes): string
    {
        if (!str_starts_with($bytes, "PK\x03\x04")) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }

        $area = AreaTemporariaPrivada::criar('textoodt');
        try {
            $xml = $this->lerOContent($area->gravar($bytes, 'odt'), \strlen($bytes));
        } finally {
            $area->liberar();
        }

        return $this->textoDo($xml);
    }

    private function lerOContent(string $caminho, int $tamanhoDoZip): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($caminho, \ZipArchive::RDONLY) !== true) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }

        try {
            $this->conferirOZip($zip, $tamanhoDoZip);
            $xml = $this->lerEntrada($zip, 'content.xml', ExtratorDeTextoDocx::TAMANHO_MAXIMO_DESCOMPACTADO + 1);
        } finally {
            $zip->close();
        }
        if ($xml === '' || \strlen($xml) > ExtratorDeTextoDocx::TAMANHO_MAXIMO_DESCOMPACTADO) {
            throw new ArquivoSemTextoException('O arquivo é grande demais depois de aberto ou não tem texto.');
        }

        return $xml;
    }

    private function lerEntrada(\ZipArchive $zip, string $nome, int $limite): string
    {
        $fluxo = $zip->getStream($nome);
        if ($fluxo === false) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }
        $conteudo = stream_get_contents($fluxo, $limite);
        fclose($fluxo);

        return \is_string($conteudo) ? $conteudo : '';
    }

    private function conferirOZip(\ZipArchive $zip, int $tamanhoDoZip): void
    {
        if ($zip->numFiles > ExtratorDeTextoDocx::MAXIMO_DE_ENTRADAS
            || $zip->locateName('mimetype') === false
            || $zip->locateName('content.xml') === false
            || $this->lerEntrada($zip, 'mimetype', 100) !== self::MIMETYPE) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }
        $declarado = $zip->statName('content.xml');
        $tamanho = \is_array($declarado) ? (int) $declarado['size'] : 0;
        if ($tamanho > ExtratorDeTextoDocx::TAMANHO_MAXIMO_DESCOMPACTADO
            || $tamanho > max(1, $tamanhoDoZip) * ExtratorDeTextoDocx::RAZAO_MAXIMA_DE_COMPRESSAO) {
            throw new ArquivoSemTextoException('O arquivo é grande demais depois de aberto.');
        }
    }

    private function textoDo(string $xml): string
    {
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new ArquivoSemTextoException(self::MENSAGEM);
        }

        $leitor = new \XMLReader();
        $anterior = libxml_use_internal_errors(true);
        try {
            if (!$leitor->XML($xml, null, LIBXML_NONET | LIBXML_NOCDATA)) {
                throw new ArquivoSemTextoException(self::MENSAGEM);
            }

            return $this->percorrer($leitor);
        } finally {
            $leitor->close();
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    private function percorrer(\XMLReader $leitor): string
    {
        $texto = '';
        $dentro = false;
        $ok = $leitor->read();
        while ($ok && \strlen($texto) < self::LIMITE_DE_LEITURA) {
            $pular = false;
            if ($leitor->namespaceURI === self::NS_OFFICE && $leitor->localName === 'text') {
                $dentro = $leitor->nodeType === \XMLReader::ELEMENT;
            } elseif ($dentro) {
                $texto .= $this->doNo($leitor, $pular);
            }
            $ok = $pular ? $leitor->next() : $leitor->read();
        }

        return $texto;
    }

    private function doNo(\XMLReader $leitor, bool &$pular): string
    {
        if ($leitor->nodeType === \XMLReader::TEXT || $leitor->nodeType === \XMLReader::SIGNIFICANT_WHITESPACE) {
            return $leitor->value;
        }
        if ($leitor->namespaceURI !== self::NS_TEXT) {
            return '';
        }
        if ($leitor->nodeType === \XMLReader::END_ELEMENT) {
            return \in_array($leitor->localName, ['p', 'h'], true) ? "\n" : '';
        }
        if ($leitor->nodeType !== \XMLReader::ELEMENT) {
            return '';
        }
        $pular = \in_array($leitor->localName, self::IGNORADOS, true);

        return match ($leitor->localName) {
            'p', 'h' => $leitor->isEmptyElement ? "\n" : '',
            'tab' => "\t",
            'line-break' => "\n",
            's' => str_repeat(' ', $this->espacos($leitor)),
            default => '',
        };
    }

    private function espacos(\XMLReader $leitor): int
    {
        $c = $leitor->getAttributeNs('c', self::NS_TEXT);

        return $c !== null && ctype_digit($c) ? max(1, min((int) $c, self::MAXIMO_DE_ESPACOS)) : 1;
    }
}
