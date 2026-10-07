<?php

declare(strict_types=1);

namespace App\Cliente\DTO;

/**
 * Um contato da janela "Detalhes do cliente" (edição inline, desenho 1.2.3
 * dc L.810-836 / L.5748-5786): QUAL dos três campos fixos do cadastro muda e
 * para que valor. Valor vazio = remover (só vale para telefone).
 *
 * `campo` é um de `AtualizarContatoDoClienteUseCase::CAMPOS`; o UseCase recusa
 * qualquer outro — o controller não filtra.
 */
final readonly class AtualizarContatoDoClienteInput
{
    public function __construct(
        public int $clienteId,
        public string $campo,
        public string $valor,
    ) {}
}
