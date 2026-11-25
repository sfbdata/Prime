<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\DTO\ContatoComercialOutput;
use App\Comercial\Repository\ContatoComercialRepository;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;

/**
 * A lista da Base de contatos: 20 por página, com a busca por e-mail ou nome (mínimo 2 letras; menos que isso lista
 * tudo). A situação de cada linha vem do descadastro da plataforma para a lista das campanhas, numa consulta por lote.
 */
final class ListarContatosDoComercialUseCase
{
    public const POR_PAGINA = 20;
    public const MINIMO_DA_BUSCA = 2;

    public function __construct(
        private readonly ContatoComercialRepository $contatos,
        private readonly DescadastroDeEmailInterface $descadastro,
    ) {
    }

    /** @return array{total: int, pagina: int, porPagina: int, itens: list<ContatoComercialOutput>} */
    public function executar(?string $busca, int $pagina, int $porPagina = self::POR_PAGINA): array
    {
        $pagina = max(1, $pagina);
        $busca = self::buscaValida($busca);
        $lista = $this->contatos->pagina($busca, $pagina, $porPagina);
        $emails = array_map(static fn (array $l): string => (string) $l['email'], $lista['itens']);
        $descadastrados = array_flip($emails === [] ? [] : $this->descadastro->filtrarDescadastrados(null, $emails, ComposicaoDaCampanha::LISTA));

        return [
            'total' => $lista['total'],
            'pagina' => $pagina,
            'porPagina' => $porPagina,
            'itens' => array_map(static fn (array $l): ContatoComercialOutput => ContatoComercialOutput::deLinha($l, isset($descadastrados[(string) $l['email']])), $lista['itens']),
        ];
    }

    public static function buscaValida(?string $busca): ?string
    {
        $busca = trim((string) $busca);

        return mb_strlen($busca) >= self::MINIMO_DA_BUSCA ? mb_substr($busca, 0, 120) : null;
    }
}
