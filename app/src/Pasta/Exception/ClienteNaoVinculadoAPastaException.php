<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * O cliente não está vinculado à pasta: não há o que desvincular (`DesvincularClienteDaPastaUseCase`). A tela responde
 * 422 (XHR) ou um aviso com {@see self::MENSAGEM}.
 */
final class ClienteNaoVinculadoAPastaException extends \DomainException
{
    public const MENSAGEM = 'Cliente não vinculado a esta pasta.';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }
}
