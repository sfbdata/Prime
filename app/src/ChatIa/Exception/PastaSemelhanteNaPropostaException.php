<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/**
 * Já há pasta do mesmo cliente e ação (409 `pasta_semelhante`, o mesmo aviso do formulário de Nova pasta). Confirmar
 * segue só com `criarMesmoAssim`. Nada foi gravado.
 */
final class PastaSemelhanteNaPropostaException extends \DomainException
{
    /** @param list<array{id: int, nup: string, nomeCliente: string, nomeAcao: string}> $semelhantes */
    public function __construct(public readonly array $semelhantes, public readonly int $total)
    {
        parent::__construct('Já existe pasta deste cliente com esta ação.');
    }

    public function codigo(): string
    {
        return 'pasta_semelhante';
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        return ['semelhantes' => $this->semelhantes, 'total' => $this->total];
    }
}
