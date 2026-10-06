<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * O pedido não pôde ser enfileirado e NÃO foi possível registrar isso no banco (EntityManager
 * fechado ou gravação da falha recusada). Nada a fingir: o controller responde 503 e o usuário
 * tenta de novo. `analiseId` é o que ficou gravado antes do dispatch, para o log.
 */
final class FilaIndisponivelException extends \RuntimeException
{
    public function __construct(public readonly ?int $analiseId, ?\Throwable $anterior = null)
    {
        parent::__construct('Não foi possível enfileirar a análise agora. Tente novamente em instantes.', 0, $anterior);
    }

    public function motivo(): string
    {
        return 'fila_indisponivel';
    }
}
