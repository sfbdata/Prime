<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * O provedor não sugeriu o agrupamento do lote: indisponível, falha da chamada ou resposta inválida. A análise já
 * foi gravada (`falhou` ou `indisponivel`) e conta na cota. A tela mantém o agrupamento por pasta.
 */
final class AgrupamentoDoLoteFalhouException extends \RuntimeException
{
    public const MENSAGEM = 'A IA não conseguiu sugerir agora. O agrupamento por pasta continua valendo.';

    public function __construct(?\Throwable $anterior = null)
    {
        parent::__construct(self::MENSAGEM, 0, $anterior);
    }
}
