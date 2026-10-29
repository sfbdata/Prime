<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * O provedor existe mas a chamada falhou: rede, timeout, 4xx/5xx, resposta malformada. `transitoria`
 * decide o destino no worker — `true` (rede, 5xx, 429) re-lança para o Messenger tentar de novo;
 * `false` (400/401/403, chave inválida) marca a análise como falha sem retry.
 *
 * `esperarSegundos` é o prazo que o provedor pediu (`retry-after` do 429/529/503): o worker re-tenta só depois
 * dele, em vez de seguir o atraso padrão do transport e bater de novo no mesmo limite.
 *
 * `deRede` marca a falha de transporte (rede, timeout, fluxo cortado), para a tela dizer "demorou demais" e não
 * "sobrecarregada" (que é 429/5xx/529 do provedor).
 */
final class FalhaDoProvedorException extends \RuntimeException
{
    public function __construct(
        string $mensagem,
        public readonly bool $transitoria,
        ?\Throwable $anterior = null,
        public readonly ?int $esperarSegundos = null,
        public readonly bool $deRede = false,
    ) {
        parent::__construct($mensagem, 0, $anterior);
    }
}
