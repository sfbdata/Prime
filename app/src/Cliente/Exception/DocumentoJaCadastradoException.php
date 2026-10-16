<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

use App\Cliente\Entity\Cliente;

/**
 * O CPF/CNPJ informado é de OUTRO cadastro do escritório (desenho 1.2.3: "Este CPF já possui cadastro no sistema.").
 * Leva o cadastro existente para a tela oferecer "Abrir cadastro" e "Utilizar cadastro existente".
 */
final class DocumentoJaCadastradoException extends \DomainException
{
    public function __construct(public readonly Cliente $existente)
    {
        parent::__construct('Este ' . ($existente instanceof \App\Cliente\Entity\ClientePF ? 'CPF' : 'CNPJ') . ' já possui cadastro no sistema.');
    }
}
