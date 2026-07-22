<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * O telefone informado ao marcar "atual" não existe para a Pessoa do escritório (tenant) atual —
 * seja por id inexistente, seja por pertencer a outra pessoa/escritório (guarda multi-tenant,
 * invariável 24). É erro de entrada do usuário (não de sistema).
 */
final class PessoaTelefoneNaoEncontradoException extends \DomainException
{
    public function __construct(int $telefoneId)
    {
        parent::__construct(sprintf('Telefone %d não encontrado para esta pessoa.', $telefoneId));
    }
}
