<?php

declare(strict_types=1);

namespace App\Pasta\Enum;

/**
 * As três chaves do cartão "Avisar quando chegar" da aba Push (desenho 1.2.3, dc L.5447 `avisos`;
 * spec push-avisar-quando-chegar §2), na ordem e com o rótulo exato do desenho. O valor é o que o
 * POST `pasta_push_avisos` recebe no campo `chave`.
 */
enum ChaveDoAvisoDoPush: string
{
    case Responsavel = 'responsavel';
    case Email = 'email';
    case Ia = 'ia';

    public function rotulo(): string
    {
        return match ($this) {
            self::Responsavel => 'Responsável da pasta',
            self::Email => 'Por e-mail',
            self::Ia => 'Análise da BlueJus IA',
        };
    }
}
