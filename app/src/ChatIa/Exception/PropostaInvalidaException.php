<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/**
 * Os dados da proposta não passam na validação do cadastro (422). `erros` aponta o campo: `campos.<chave>` (cliente) ou
 * `pasta.<nomeCliente|nomeAcao|nup>`; a chave vazia é um erro geral. Nada foi gravado.
 */
final class PropostaInvalidaException extends \DomainException
{
    /** @param array<string, string> $erros campo => mensagem */
    public function __construct(public readonly array $erros, ?string $mensagem = null)
    {
        parent::__construct($mensagem ?? 'Confira os campos destacados.');
    }

    public function codigo(): string
    {
        return 'proposta_invalida';
    }
}
