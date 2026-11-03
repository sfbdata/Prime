<?php

declare(strict_types=1);

namespace App\Blog\DTO;

use App\Blog\Enum\BlogsDoArtigo;

/**
 * Pedido de importar um `.md` do repositório dos sites (`blog:importar-do-repositorio`, fatia P2).
 *
 * `blogs` é o blog de origem do arquivo: `normal` (pasta blog/), `gratis` (pasta blog-gratis/) ou `ambos`, quando o
 * comando achou o mesmo slug nas duas pastas com o mesmo texto.
 */
final readonly class ImportarArtigoDoRepositorioInput
{
    /**
     * @param list<string> $slugsDaImportacao slugs de todos os arquivos desta rodada: um relacionado pode apontar
     *                                        para um artigo que ainda vai ser importado
     */
    public function __construct(
        public string $conteudo,
        public BlogsDoArtigo $blogs,
        public bool $simular = false,
        public array $slugsDaImportacao = [],
    ) {
    }
}
