<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/**
 * Tipo de uma mensagem do chat da demanda. `Interna` só é vista por Master e Equipe; `Sistema`,
 * `Andamento` e `Arquivo` são registros que as ações gravam no chat, como no desenho.
 */
enum TipoMensagem: string
{
    case Normal = 'normal';
    case Interna = 'interna';
    case Sistema = 'sistema';
    case Andamento = 'andamento';
    case Arquivo = 'arquivo';
    /** Registro do dinheiro (aviso do PIX, comprovante, recebimento): traz o valor, então o Convidado nunca o lê. */
    case Pagamento = 'pagamento';

    /** Sufixo do cabeçalho da mensagem no chat (`m.tipo` do desenho). */
    public function sufixo(): string
    {
        return match ($this) {
            self::Normal => '',
            self::Interna => '· nota interna (só equipe)',
            self::Sistema, self::Pagamento => '· registro',
            self::Andamento => '· andamento',
            self::Arquivo => '· arquivo',
        };
    }

    /** Registro gravado pelo sistema (não se apaga; estilo "registro" no chat). */
    public function ehRegistroDoSistema(): bool
    {
        return $this === self::Sistema || $this === self::Pagamento;
    }
}
