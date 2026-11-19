<?php

declare(strict_types=1);

namespace App\Shared\Texto;

/**
 * Os formatos de arquivo de que o {@see ExtratorDeTexto} sabe tirar texto. O valor é o tipo que o upload grava.
 */
enum FormatoDeTexto: string
{
    case PecaDoEditor = 'text/html';
    case Docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    case Doc = 'application/msword';
    case Odt = 'application/vnd.oasis.opendocument.text';
    case Rtf = 'application/rtf';
    case Txt = 'text/plain';
    case Csv = 'text/csv';
    case Xml = 'application/xml';
    case Kml = 'application/vnd.google-earth.kml+xml';

    /**
     * O formato pelo tipo que o upload gravou: minúsculas, sem espaços, parâmetros como "; charset=" ignorados e
     * os sinônimos conhecidos (`text/rtf`, `text/xml`) levados ao formato. Tipo desconhecido devolve null.
     */
    public static function doTipoDeclarado(string $mimeType): ?self
    {
        $tipo = strtolower(trim(explode(';', $mimeType, 2)[0]));

        return match ($tipo) {
            'text/rtf' => self::Rtf,
            'text/xml' => self::Xml,
            default => self::tryFrom($tipo),
        };
    }

    /** Teto de bytes do arquivo (os mesmos do upload de peça). */
    public function tamanhoMaximo(): int
    {
        return match ($this) {
            self::Docx, self::Doc, self::Odt, self::Rtf => 10 * 1024 * 1024,
            self::PecaDoEditor, self::Txt, self::Csv, self::Xml, self::Kml => 5 * 1024 * 1024,
        };
    }
}
