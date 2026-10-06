<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * O provedor existe mas a chamada falhou: rede, timeout, 4xx/5xx, resposta malformada. `transitoria`
 * decide o destino no worker — `true` (rede, 5xx, 429) re-lança para o Messenger tentar de novo;
 * `false` (400/401/403, chave inválida) marca a análise como falha sem retry.
 */
final class FalhaDoProvedorException extends \RuntimeException
{
    public function __construct(
        string $mensagem,
        public readonly bool $transitoria,
        ?\Throwable $anterior = null,
    ) {
        parent::__construct($mensagem, 0, $anterior);
    }
}
