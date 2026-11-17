<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/** Formato do arquivo da minuta de ata: Word (.docx, com papel timbrado opcional) ou PDF. */
enum FormatoDaMinuta: string
{
    case Docx = 'docx';
    case Pdf = 'pdf';

    public function tipoDeConteudo(): string
    {
        return match ($this) {
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Pdf => 'application/pdf',
        };
    }
}
