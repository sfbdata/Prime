<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro;

/**
 * Uma página dos descadastros ATIVOS de um escopo ({@see DescadastroDeEmailInterface::listar()}):
 * o total do filtro (para a paginação) e os itens da página, do mais recente para o mais antigo.
 */
final readonly class ListaDeDescadastros
{
    /** @param list<ItemDeDescadastro> $itens */
    public function __construct(
        public int $total,
        public array $itens,
        public int $pagina,
        public int $porPagina,
    ) {
    }
}
