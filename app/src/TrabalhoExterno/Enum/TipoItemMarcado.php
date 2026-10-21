<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/** Os quatro itens que cada usuário marca para si: estrela, importância, lixeira, lida (spec VS 18.10.26 §2). */
enum TipoItemMarcado: string
{
    case Demanda = 'demanda';
    case Vaga = 'vaga';
    case Curriculo = 'curriculo';
    case Candidatura = 'candidatura';
}
