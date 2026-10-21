<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * Uma linha de "Para quem?" na tela de despacho: uma conversa minha (1:1 ou grupo) ou um colega sem conversa comigo.
 * Grupo em que alguém não vê a pasta vem com `disponivel = false` e o `motivo`; colega que não vê nem aparece.
 */
final readonly class DestinoDoEncaminhamentoOutput
{
    public const CONVERSA = 'conversa';
    public const COLEGA = 'colega';

    public function __construct(
        public string $tipo,
        public ?int $conversaId,
        public ?int $usuarioId,
        public string $nome,
        public bool $grupo,
        public bool $disponivel,
        public ?string $motivo,
    ) {
    }

    /** @return array{tipo: string, conversaId: ?int, usuarioId: ?int, nome: string, grupo: bool, disponivel: bool, motivo: ?string} */
    public function toArray(): array
    {
        return [
            'tipo' => $this->tipo,
            'conversaId' => $this->conversaId,
            'usuarioId' => $this->usuarioId,
            'nome' => $this->nome,
            'grupo' => $this->grupo,
            'disponivel' => $this->disponivel,
            'motivo' => $this->motivo,
        ];
    }
}
