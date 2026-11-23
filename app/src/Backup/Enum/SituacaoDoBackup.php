<?php

declare(strict_types=1);

namespace App\Backup\Enum;

/**
 * Situação de um pedido de backup (spec backup-e-exportacao-de-dados, seção 3): pedido (na fila), montando (o worker
 * começou), pronto (o ZIP está no armazenamento e pode ser baixado até `expira_em`), falhou, vencido (o ZIP já foi
 * apagado). As transições moram em {@see \App\Backup\Entity\PedidoDeBackup}.
 */
enum SituacaoDoBackup: string
{
    case Pedido = 'pedido';
    case Montando = 'montando';
    case Pronto = 'pronto';
    case Falhou = 'falhou';
    case Vencido = 'vencido';

    public function rotulo(): string
    {
        return match ($this) {
            self::Pedido => 'Na fila',
            self::Montando => 'Em preparo',
            self::Pronto => 'Pronto',
            self::Falhou => 'Falhou',
            self::Vencido => 'Vencido',
        };
    }

    /** Ainda vai ser montado: conta para a regra de um pedido aberto por vez. */
    public function estaAberto(): bool
    {
        return self::Pedido === $this || self::Montando === $this;
    }
}
