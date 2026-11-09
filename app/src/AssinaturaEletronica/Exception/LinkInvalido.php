<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/**
 * Token malformado, desconhecido, de documento apagado ou de signatário que não existe: tudo vira o mesmo 404 "Link
 * inválido.", sem dizer qual (contrato da etapa 2, seção 5.2).
 */
final class LinkInvalido extends \RuntimeException
{
    public const CHAVE = 'assinatura_eletronica.erro.link_invalido';

    public function __construct()
    {
        parent::__construct(self::CHAVE);
    }
}
