<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * Não há provedor de linguagem configurado (ou ele está desligado). Não é falha de rede: repetir
 * não resolve — a análise vira `indisponivel` e a mensagem NÃO volta para a fila.
 */
final class ProvedorIndisponivelException extends \RuntimeException
{
}
