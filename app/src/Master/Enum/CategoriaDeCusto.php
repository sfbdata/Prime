<?php

declare(strict_types=1);

namespace App\Master\Enum;

/** As categorias de custo do desenho (05 Perfis 1.2, `CAT`): fixas no código (spec docs/specs/master-custos.md D9). */
enum CategoriaDeCusto: string
{
    case Armazenamento = 'armazenamento';
    case BancoDeDados = 'banco_de_dados';
    case Hospedagem = 'hospedagem';
    case Trafego = 'trafego';
    case Backups = 'backups';
    case ApisDeIa = 'apis_de_ia';
    case Notificacoes = 'notificacoes';
    case Integracoes = 'integracoes';
    case Outros = 'outros';

    /** Entram no custo por GB (desenho: `/Armazen|Backup/`). */
    public function contaNoCustoPorGb(): bool
    {
        return $this === self::Armazenamento || $this === self::Backups;
    }
}
