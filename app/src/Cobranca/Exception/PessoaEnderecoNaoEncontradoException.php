<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * O endereço informado ao marcar "atual" não existe para a Pessoa do escritório (tenant) atual —
 * seja por id inexistente, seja por pertencer a outra pessoa/escritório (guarda multi-tenant,
 * invariável 24). É erro de entrada do usuário (não de sistema).
 */
final class PessoaEnderecoNaoEncontradoException extends \DomainException
{
    public function __construct(int $enderecoId)
    {
        parent::__construct(sprintf('Endereço %d não encontrado para esta pessoa.', $enderecoId));
    }
}
