<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * Quem tem o acesso restrito às pastas sob responsabilidade tentou trocar o responsável de uma pasta (spec
 * `docs/specs/acesso-restrito-e-compartilhar.md`, §5.1 item 5). Passar a pasta adiante a tiraria do alcance de quem
 * a passou; quem transfere é o escritório. O controller responde 403 com a mensagem.
 */
final class ResponsavelFixoNoAcessoRestritoException extends \DomainException
{
    public const MENSAGEM = 'No acesso restrito, a pasta fica sob a sua responsabilidade. Peça ao escritório para transferir.';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }
}
