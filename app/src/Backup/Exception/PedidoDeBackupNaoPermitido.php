<?php

declare(strict_types=1);

namespace App\Backup\Exception;

/** A pessoa não pode pedir outro backup agora. A mensagem é curta e vai direto para a tela. */
final class PedidoDeBackupNaoPermitido extends \DomainException
{
    /** Chave de tradução (domínio `messages`) da mensagem que a tela mostra; `getMessage()` fica em português para log e teste. */
    public string $chaveDeTraducao = '';

    public static function jaTemPedidoAberto(): self
    {
        return self::com('Você já tem um backup sendo preparado. Aguarde ele ficar pronto.', 'perfil.backup.recusa.ja_tem_aberto');
    }

    public static function limiteDoDia(): self
    {
        return self::com('Você já pediu um backup nas últimas 24 horas. Tente de novo mais tarde.', 'perfil.backup.recusa.limite_do_dia');
    }

    private static function com(string $mensagem, string $chave): self
    {
        $e = new self($mensagem);
        $e->chaveDeTraducao = $chave;

        return $e;
    }
}
