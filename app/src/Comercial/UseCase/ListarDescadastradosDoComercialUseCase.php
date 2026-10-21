<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\DTO\DescadastradoDoComercialOutput;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;

/**
 * A lista de Descadastrados do Comercial (spec comercial-e-emails §10, item 9; desenho 05 VS 21.10.26, L245-249):
 * os pedidos ativos da plataforma para as campanhas (`comercial_campanhas`) e o opt-out total (`todos`), do mais
 * recente para o mais antigo, com a busca no e-mail.
 */
final class ListarDescadastradosDoComercialUseCase
{
    public const POR_PAGINA = 50;

    public function __construct(
        private readonly DescadastroDeEmailInterface $descadastro,
    ) {
    }

    /** @return array{total: int, pagina: int, porPagina: int, itens: list<DescadastradoDoComercialOutput>} */
    public function executar(?string $busca, int $pagina): array
    {
        $lista = $this->descadastro->listar(null, $busca, $pagina, self::POR_PAGINA, ResumirComercialUseCase::ASSUNTOS_DA_LISTA);

        return [
            'total' => $lista->total,
            'pagina' => $lista->pagina,
            'porPagina' => $lista->porPagina,
            'itens' => array_map(DescadastradoDoComercialOutput::deItem(...), $lista->itens),
        ];
    }
}
