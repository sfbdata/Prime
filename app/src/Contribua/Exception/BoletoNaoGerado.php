<?php

declare(strict_types=1);

namespace App\Contribua\Exception;

/** O banco falhou ou recusou o boleto. A mensagem é a que a tela mostra; o motivo do banco fica só no log. */
final class BoletoNaoGerado extends \RuntimeException
{
    public const MENSAGEM = 'Não foi possível gerar o boleto agora. Tente de novo em instantes.';

    public function __construct(?\Throwable $anterior = null)
    {
        parent::__construct(self::MENSAGEM, 0, $anterior);
    }
}
