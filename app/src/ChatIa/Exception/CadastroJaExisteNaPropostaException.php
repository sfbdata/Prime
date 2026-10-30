<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/**
 * O CPF/CNPJ da proposta já é de um cadastro do escritório (409 `duplicada`). Leva o cadastro existente para o cartão
 * oferecer abri-lo e, na proposta de pasta, "usar o existente". Nada foi gravado.
 */
final class CadastroJaExisteNaPropostaException extends \DomainException
{
    public function __construct(
        public readonly int $clienteId,
        public readonly string $nome,
        public readonly string $documento,
        public readonly bool $podeUsarExistente,
    ) {
        parent::__construct('Este ' . $documento . ' já possui cadastro no sistema.');
    }

    public function codigo(): string
    {
        return 'duplicada';
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        return ['existente' => ['id' => $this->clienteId, 'nome' => $this->nome, 'documento' => $this->documento], 'podeUsarExistente' => $this->podeUsarExistente];
    }
}
