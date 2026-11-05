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
    /**
     * Registro da negociação da Central com o cliente (proposta com o valor, motivos de reabrir, de pedir e responder
     * informações, de pedir ajustes, de recusar e de aceitar a proposta): o Convidado nunca o lê, nem quando a
     * demanda é reaberta e renegociada depois do aceite do convite (achado 2.09).
     */
    case Negociacao = 'negociacao';

    /** Sufixo do cabeçalho da mensagem no chat (`m.tipo` do desenho). */
    public function sufixo(): string
    {
        return match ($this) {
            self::Normal => '',
            self::Interna => '· nota interna (só equipe)',
            self::Sistema, self::Pagamento, self::Negociacao => '· registro',
            self::Andamento => '· andamento',
            self::Arquivo => '· arquivo',
        };
    }

    /** Registro gravado pelo sistema (não se apaga; estilo "registro" no chat). */
    public function ehRegistroDoSistema(): bool
    {
        return $this === self::Sistema || $this === self::Pagamento || $this === self::Negociacao;
    }
}
