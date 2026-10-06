<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * A seleção da aba Documentos passou de um teto da ação (S-11: 500 arquivos / 1 GB por .zip; 2.000
 * documentos / 1 GB por cópia). Estende `InvalidArgumentException` para cair no mesmo 422 das
 * outras recusas de lote; a mensagem é a que a tela mostra.
 */
final class SelecaoAcimaDoTetoException extends \InvalidArgumentException
{
    public static function porArquivos(int $quantidade, int $teto): self
    {
        return new self(sprintf(
            'A seleção tem %s arquivos; o limite por ação é %s.',
            number_format($quantidade, 0, ',', '.'),
            number_format($teto, 0, ',', '.'),
        ));
    }

    public static function porBytes(int $bytes, int $teto): self
    {
        return new self(sprintf(
            'A seleção soma %s; o limite por ação é %s.',
            self::tamanhoLegivel($bytes),
            self::tamanhoLegivel($teto),
        ));
    }

    /** `1,5 GB`, `820 MB`, `12 KB` — para a mensagem, não para contar. */
    public static function tamanhoLegivel(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / 1024 ** 3, 1, ',', '.') . ' GB';
        }
        if ($bytes >= 1024 ** 2) {
            return number_format($bytes / 1024 ** 2, 0, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        }

        return $bytes . ' B';
    }
}
