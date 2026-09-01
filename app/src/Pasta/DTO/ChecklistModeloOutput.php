<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PastaChecklistModelo;

/**
 * Um modelo como o painel de modelos precisa mostrá-lo: nome, quantos itens tem e
 * quais são (para a prévia antes de aplicar).
 *
 * Existe para o controller nunca devolver a entidade Doctrine ao JSON — o que
 * arrastaria autor, tenant e a pasta inteira por trás das relações.
 */
final readonly class ChecklistModeloOutput
{
    /** @param string[] $itens títulos, na ordem do modelo */
    public function __construct(
        public int $id,
        public string $nome,
        public int $totalItens,
        public array $itens,
    ) {}

    public static function fromEntity(PastaChecklistModelo $modelo): self
    {
        $titulos = [];
        foreach ($modelo->getItens() as $item) {
            $titulos[] = $item->getTitulo();
        }

        return new self(
            id: (int) $modelo->getId(),
            nome: $modelo->getNome(),
            totalItens: count($titulos),
            itens: $titulos,
        );
    }

    /** @return array{id: int, nome: string, totalItens: int, itens: string[]} */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'nome'       => $this->nome,
            'totalItens' => $this->totalItens,
            'itens'      => $this->itens,
        ];
    }
}
