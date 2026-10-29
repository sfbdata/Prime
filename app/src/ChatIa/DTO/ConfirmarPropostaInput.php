<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\ChatIa\Exception\PropostaInvalidaException;

/**
 * O corpo (opcional) de `POST /chat-ia/propostas/{id}/confirmar`:
 * `{campos?, pasta?: {nomeCliente, nomeAcao, nup}, criarMesmoAssim?, usarExistente?}`.
 *
 * Os valores editados no cartão: `campos` troca TODOS os campos do cliente da proposta e `pasta` os dados da pasta. O que
 * vale é o que a pessoa enviou no clique, e o UseCase revalida tudo. Aqui só a FORMA: tipos errados são 422.
 */
final readonly class ConfirmarPropostaInput
{
    /**
     * @param array<string, mixed>|null $campos nulo = a pessoa não editou (valem os da proposta)
     * @param array<string, mixed>|null $pasta
     */
    public function __construct(
        public ?array $campos = null,
        public ?array $pasta = null,
        public bool $criarMesmoAssim = false,
        public bool $usarExistente = false,
    ) {
    }

    /** @param array<string, mixed> $corpo o JSON da requisição */
    public static function deCorpo(array $corpo): self
    {
        foreach (['campos', 'pasta'] as $chave) {
            if (isset($corpo[$chave]) && !\is_array($corpo[$chave])) {
                throw new PropostaInvalidaException(['' => 'Pedido inválido.']);
            }
        }
        foreach (['criarMesmoAssim', 'usarExistente'] as $chave) {
            if (isset($corpo[$chave]) && !\is_bool($corpo[$chave])) {
                throw new PropostaInvalidaException(['' => 'Pedido inválido.']);
            }
        }

        return new self($corpo['campos'] ?? null, $corpo['pasta'] ?? null, ($corpo['criarMesmoAssim'] ?? false) === true, ($corpo['usarExistente'] ?? false) === true);
    }
}
