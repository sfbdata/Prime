<?php

declare(strict_types=1);

namespace App\Backup\Exception;

/** A pessoa não pode pedir outro backup agora. A mensagem é curta e vai direto para a tela. */
final class PedidoDeBackupNaoPermitido extends \DomainException
{
    public static function jaTemPedidoAberto(): self
    {
        return new self('Você já tem um backup sendo preparado. Aguarde ele ficar pronto.');
    }

    public static function limiteDoDia(): self
    {
        return new self('Você já pediu um backup nas últimas 24 horas. Tente de novo mais tarde.');
    }
}
