<?php

declare(strict_types=1);

namespace App\Pasta\Service\EnvioAoCliente;

/**
 * Espelho do `TipoDeEnvio` do Carteiro (frente 5d, `App\Comercial\Envio`): transacional (o envio
 * manual da Análise IA) ou de lista (o automático do P4d, lista `push_cliente`).
 */
enum TipoDeEmailAoCliente: string
{
    case Transacional = 'transacional';
    case Lista = 'lista';
}
