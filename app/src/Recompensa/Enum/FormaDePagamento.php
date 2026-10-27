<?php

declare(strict_types=1);

namespace App\Recompensa\Enum;

/** Como o Master pagou a recompensa FORA do sistema (diálogo "Dar baixa"; spec perfis-1.2-vs-25-10, F4). */
enum FormaDePagamento: string
{
    case PIX = 'pix';
    case TRANSFERENCIA = 'transferencia';
    case DESCONTO_NA_MENSALIDADE = 'desconto_na_mensalidade';
    case CREDITO_DE_ESPACO = 'credito_de_espaco';
}
