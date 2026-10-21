<?php

declare(strict_types=1);

namespace App\Comercial\Consentimento;

/** O que a rota de confirmação mostra. Nenhum caso revela o endereço. */
enum ResultadoDaConfirmacao: string
{
    case Confirmado = 'confirmado';
    case VencidoOuUsado = 'vencido_ou_usado';
}
