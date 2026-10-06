<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * O modelo respondeu, mas não no JSON pedido (ou sem `resumo`). O texto integral fica preservado
 * para auditoria/reparse em `texto_bruto` — a análise vira `falhou` com motivo 'resposta inválida'.
 */
final class RespostaInvalidaException extends \RuntimeException
{
    public function __construct(public readonly string $textoBruto, string $detalhe = 'resposta inválida')
    {
        parent::__construct($detalhe);
    }
}
