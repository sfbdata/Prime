<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\DTO\ArtigoDaListaOutput;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;

/**
 * Lista da seção "Artigos dos blogs" (busca no título e no slug, filtro de situação e de blog). Somente leitura; o
 * controller exige ROLE_SUPER_ADMIN. Filtro com valor desconhecido é ignorado (lista tudo).
 */
final class ListarArtigosDoBlogUseCase
{
    public const POR_PAGINA = 50;

    public function __construct(private readonly ArtigoDoBlogRepository $artigos)
    {
    }

    /** @return array{itens: list<ArtigoDaListaOutput>, total: int, pagina: int, porPagina: int} */
    public function executar(?string $busca, ?string $situacao, ?string $blogs, int $pagina = 1): array
    {
        $pagina = max(1, $pagina);
        $resultado = $this->artigos->listar(
            $busca,
            $situacao === null ? null : SituacaoDoArtigo::tryFrom($situacao),
            $blogs === null ? null : BlogsDoArtigo::tryFrom($blogs),
            $pagina,
            self::POR_PAGINA,
        );

        $itens = [];
        foreach ($resultado as $artigo) {
            $itens[] = ArtigoDaListaOutput::fromEntity($artigo);
        }

        return ['itens' => $itens, 'total' => \count($resultado), 'pagina' => $pagina, 'porPagina' => self::POR_PAGINA];
    }
}
