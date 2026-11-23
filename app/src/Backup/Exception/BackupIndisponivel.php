<?php

declare(strict_types=1);

namespace App\Backup\Exception;

/**
 * O backup não pode ser baixado por quem pediu agora: não existe neste escritório, é de outra pessoa, ainda não está
 * pronto, venceu ou o arquivo sumiu. A rota responde sempre 404, sem dizer qual dos motivos foi.
 */
final class BackupIndisponivel extends \DomainException
{
    public static function porQualquerMotivo(): self
    {
        return new self('Backup indisponível.');
    }
}
