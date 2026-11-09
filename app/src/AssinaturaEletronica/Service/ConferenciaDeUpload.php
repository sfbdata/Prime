<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Confere os arquivos do "Salvar rascunho" antes de qualquer gravação: o tipo pelo CONTEÚDO (não pela extensão nem
 * pelo que o navegador diz) e o tamanho. PDF com o teto do upload de documento da pasta; logo PNG ou JPEG até 2 MB.
 */
final class ConferenciaDeUpload
{
    public const PDF_MAXIMO_EM_BYTES = 10 * 1024 * 1024;
    public const LOGO_MAXIMO_EM_BYTES = 2 * 1024 * 1024;
    private const TIPOS_DE_LOGO = ['image/png', 'image/jpeg'];

    private function __construct()
    {
    }

    /** @throws AcaoRecusada */
    public static function pdf(UploadedFile $arquivo): void
    {
        self::exigirRecebido($arquivo);

        if ($arquivo->getMimeType() !== 'application/pdf') {
            throw new AcaoRecusada('assinatura_eletronica.erro.arquivo_invalido');
        }

        if ((int) $arquivo->getSize() > self::PDF_MAXIMO_EM_BYTES) {
            throw new AcaoRecusada('assinatura_eletronica.erro.arquivo_grande');
        }
    }

    /** @throws AcaoRecusada */
    public static function logo(UploadedFile $arquivo): void
    {
        self::exigirRecebido($arquivo);

        if (!\in_array($arquivo->getMimeType(), self::TIPOS_DE_LOGO, true)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.logo_arquivo_invalido');
        }

        if ((int) $arquivo->getSize() > self::LOGO_MAXIMO_EM_BYTES) {
            throw new AcaoRecusada('assinatura_eletronica.erro.logo_grande');
        }
    }

    /** @throws AcaoRecusada */
    private static function exigirRecebido(UploadedFile $arquivo): void
    {
        if (!$arquivo->isValid()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.upload_falhou');
        }
    }
}
