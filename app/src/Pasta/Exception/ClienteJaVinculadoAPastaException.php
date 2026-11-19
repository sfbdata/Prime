<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * O cliente já está vinculado à pasta: vincular de novo não duplica (`VincularClienteAPastaUseCase`). A tela responde
 * 422 com {@see self::MENSAGEM}.
 */
final class ClienteJaVinculadoAPastaException extends \DomainException
{
    public const MENSAGEM = 'Este cliente já está vinculado à pasta.';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }
}
