<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * O que a coluna "2 · PARA QUEM" e a linha de destinatários mostram (dc L.237, L.252, L.539): o número de cada público
 * na grade (o da fonte, como o dc), e, do público escolhido, quantos recebem, quantos descadastrados e quantos sem
 * autorização saíram, e o primeiro destinatário (o exemplo da prévia, dc L.537).
 */
final class ContagemDosPublicos
{
    /** @param array<string, int> $publicos */
    public function __construct(
        public readonly array $publicos,
        public readonly int $destinatarios,
        public readonly int $descadastrados,
        public readonly int $semAutorizacao,
        public readonly ?DestinatarioDaCampanha $exemplo,
    ) {
    }

    /** @return array{publicos: array<string, int>, destinatarios: int, descadastrados: int, semAutorizacao: int, exemplo: array{email: string, nome: string}|null} */
    public function paraArray(): array
    {
        return [
            'publicos' => $this->publicos, 'destinatarios' => $this->destinatarios, 'descadastrados' => $this->descadastrados,
            'semAutorizacao' => $this->semAutorizacao, 'exemplo' => $this->exemplo?->paraArray(),
        ];
    }
}
