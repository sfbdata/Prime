<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Exception\AnexoDoChatInvalidoException;
use App\Shared\Armazenamento\AreaTemporariaPrivada;

/**
 * Extrai o TEXTO de um DOCX (Chat I.A, spec chat-pagina-inteira decisão 6): `word/document.xml` do ZIP, só parágrafos,
 * tabulações e quebras; nada de estilo, imagem, macro, cabeçalho ou comentário.
 *
 * Defesas, nesta ordem:
 * - assinatura `PK` e `[Content_Types].xml` + `word/document.xml` presentes (o tipo é o CONTEÚDO, nunca a extensão);
 * - **zip bomb**: no máximo {@see MAXIMO_DE_ENTRADAS} entradas e {@see TAMANHO_MAXIMO_DESCOMPACTADO} bytes lidos do
 *   `document.xml` (a leitura é limitada, não confia no tamanho declarado) e razão de compressão de até
 *   {@see RAZAO_MAXIMA_DE_COMPRESSAO};
 * - XML com `<!DOCTYPE` ou `<!ENTITY` é recusado (entidade externa e "billion laughs"); o leitor roda com `LIBXML_NONET`;
 * - o texto é cortado em {@see MAXIMO_DE_CARACTERES} caracteres, com um aviso no fim.
 *
 * O ZIP vai para uma área temporária PRIVADA (o ZipArchive só abre arquivo) que é apagada no `finally`.
 */
final class ExtratorDeTextoDocx
{
    public const MAXIMO_DE_CARACTERES = 60_000;
    public const TAMANHO_MAXIMO_DESCOMPACTADO = 20 * 1024 * 1024;
    public const MAXIMO_DE_ENTRADAS = 2000;
    public const RAZAO_MAXIMA_DE_COMPRESSAO = 200;
    public const AVISO_DE_CORTE = "\n[Conteúdo cortado: o arquivo tem mais de 60 mil caracteres.]";

    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @throws AnexoDoChatInvalidoException */
    public function extrair(string $bytes): string
    {
        if (!str_starts_with($bytes, "PK\x03\x04")) {
            throw new AnexoDoChatInvalidoException('O arquivo não é um DOCX válido.');
        }

        $area = AreaTemporariaPrivada::criar('chatiadocx');
        try {
            $xml = $this->lerODocumento($area->gravar($bytes, 'docx'), \strlen($bytes));
        } finally {
            $area->liberar();
        }

        return $this->textoDo($xml);
    }

    private function lerODocumento(string $caminho, int $tamanhoDoZip): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($caminho, \ZipArchive::RDONLY) !== true) {
            throw new AnexoDoChatInvalidoException('O arquivo não é um DOCX válido.');
        }

        try {
            $this->conferirOZip($zip, $tamanhoDoZip);
            $fluxo = $zip->getStream('word/document.xml');
            $xml = $fluxo === false ? false : stream_get_contents($fluxo, self::TAMANHO_MAXIMO_DESCOMPACTADO + 1);
            if (\is_resource($fluxo)) {
                fclose($fluxo);
            }
        } finally {
            $zip->close();
        }
        if (!\is_string($xml) || $xml === '' || \strlen($xml) > self::TAMANHO_MAXIMO_DESCOMPACTADO) {
            throw new AnexoDoChatInvalidoException('O arquivo é grande demais depois de aberto ou não tem texto.');
        }

        return $xml;
    }

    private function conferirOZip(\ZipArchive $zip, int $tamanhoDoZip): void
    {
        if ($zip->numFiles > self::MAXIMO_DE_ENTRADAS
            || $zip->locateName('[Content_Types].xml') === false
            || $zip->locateName('word/document.xml') === false) {
            throw new AnexoDoChatInvalidoException('O arquivo não é um DOCX válido.');
        }
        $declarado = $zip->statName('word/document.xml');
        $tamanho = \is_array($declarado) ? (int) $declarado['size'] : 0;
        if ($tamanho > self::TAMANHO_MAXIMO_DESCOMPACTADO || $tamanho > max(1, $tamanhoDoZip) * self::RAZAO_MAXIMA_DE_COMPRESSAO) {
            throw new AnexoDoChatInvalidoException('O arquivo é grande demais depois de aberto.');
        }
    }

    private function textoDo(string $xml): string
    {
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new AnexoDoChatInvalidoException('O arquivo não é um DOCX válido.');
        }

        $leitor = new \XMLReader();
        $anterior = libxml_use_internal_errors(true);
        try {
            if (!$leitor->XML($xml, null, LIBXML_NONET | LIBXML_NOCDATA)) {
                throw new AnexoDoChatInvalidoException('O arquivo não é um DOCX válido.');
            }
            $texto = $this->percorrer($leitor);
        } finally {
            $leitor->close();
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }

        return $this->aparar($texto);
    }

    private function percorrer(\XMLReader $leitor): string
    {
        $texto = '';
        while ($leitor->read() && mb_strlen($texto) <= self::MAXIMO_DE_CARACTERES) {
            if ($leitor->namespaceURI !== self::NS_W) {
                continue;
            }
            $texto .= $this->doNo($leitor);
        }

        return $texto;
    }

    private function doNo(\XMLReader $leitor): string
    {
        if ($leitor->nodeType === \XMLReader::END_ELEMENT) {
            return $leitor->localName === 'p' ? "\n" : '';
        }
        if ($leitor->nodeType !== \XMLReader::ELEMENT) {
            return '';
        }

        return match ($leitor->localName) {
            't' => $leitor->readString(),
            'tab' => "\t",
            'br', 'cr' => "\n",
            default => '',
        };
    }

    private function aparar(string $texto): string
    {
        $texto = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $texto)));
        if (mb_strlen($texto) > self::MAXIMO_DE_CARACTERES) {
            return mb_substr($texto, 0, self::MAXIMO_DE_CARACTERES) . self::AVISO_DE_CORTE;
        }
        if ($texto === '') {
            throw new AnexoDoChatInvalidoException('O DOCX não tem texto para ler.');
        }

        return $texto;
    }
}
