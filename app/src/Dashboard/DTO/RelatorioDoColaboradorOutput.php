<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * Modal "Ver relatório de X" do Dashboard (desenho 01 - Dashboard, `comVer`): os registros da pessoa
 * (mensagens, observações financeiras e de detalhes), agrupados por pasta, mais novos primeiro. O
 * `total` é o número da célula do Relatório (mesmo critério); `restantes` é o que o limite de linhas
 * deixou de fora e ainda soma no total. Os textos da tela são montados no JS pelo catálogo. Registro de aba que a pessoa logada não vê vem com `oculto` e sem trecho.
 */
final class RelatorioDoColaboradorOutput
{
    /**
     * @param list<array{pastaId: int, titulo: string, cliente: ?string, ancora: string, itens: list<array{id: int, aba: string, data: string, hora: string, trecho: string, oculto: bool}>}> $grupos
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $nome,
        public readonly int $total,
        public readonly int $restantes,
        public readonly array $grupos,
        /** Corte do "Zerar" aplicado (ISO 8601), ou null com a contagem completa. */
        public readonly ?string $corte,
    ) {
    }

    /**
     * @param callable(int): string $urlDaPasta rota da pasta (o UseCase não conhece o roteador)
     *
     * @return array<string, mixed>
     */
    public function paraArray(callable $urlDaPasta): array
    {
        $grupos = [];
        foreach ($this->grupos as $g) {
            $grupos[] = $g + ['url' => $urlDaPasta($g['pastaId']) . '#' . $g['ancora']];
        }

        return [
            'userId'    => $this->userId,
            'nome'      => $this->nome,
            'total'     => $this->total,
            'restantes' => $this->restantes,
            'corte'     => $this->corte,
            'grupos'    => $grupos,
        ];
    }
}
