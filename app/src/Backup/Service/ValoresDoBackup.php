<?php

declare(strict_types=1);

namespace App\Backup\Service;

/** Formatos de valor comuns aos JSONs do backup: datas em ISO 8601 e nada de objeto. */
final class ValoresDoBackup
{
    private function __construct()
    {
    }

    public static function data(?\DateTimeInterface $data): ?string
    {
        return $data?->format(\DATE_ATOM);
    }

    public static function dia(?\DateTimeInterface $data): ?string
    {
        return $data?->format('Y-m-d');
    }
}
