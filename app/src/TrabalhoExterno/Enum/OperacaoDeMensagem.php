<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/** Operações de cada usuário sobre mensagens do chat (`_chatLote()` do desenho). Valem só para quem marcou. */
enum OperacaoDeMensagem: string
{
    case Lixeira = 'lixeira';
    case Restaurar = 'restaurar';
    case Definitivo = 'definitivo';
    case Favoritar = 'fav';
    case Desfavoritar = 'desfav';

    public function aviso(int $n): string
    {
        $uma = $n === 1;

        return match ($this) {
            self::Lixeira => $n . ($uma ? ' mensagem movida' : ' mensagens movidas') . ' para a lixeira',
            self::Restaurar => $n . ($uma ? ' mensagem restaurada' : ' mensagens restauradas'),
            self::Definitivo => $n . ($uma ? ' mensagem excluída' : ' mensagens excluídas') . ' definitivamente',
            self::Favoritar => $n . ' mensagem(ns) favoritada(s)',
            self::Desfavoritar => $n . ' mensagem(ns) sem favorito',
        };
    }
}
