<?php

declare(strict_types=1);

namespace App\Blog\DTO;

use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;

/**
 * O que a importação fez com um artigo: importou (ou, simulando, importaria) ou pulou porque o slug já existe.
 * `avisos` lista o que ficou de fora (a capa, campos que o BlueJus não conhece).
 */
final readonly class ImportacaoDoArtigoOutput
{
    /** @param list<string> $avisos */
    private function __construct(
        public string $slug,
        public bool $importado,
        public ?BlogsDoArtigo $blogs,
        public ?SituacaoDoArtigo $situacao,
        public array $avisos,
    ) {
    }

    /** @param list<string> $avisos */
    public static function importado(string $slug, BlogsDoArtigo $blogs, SituacaoDoArtigo $situacao, array $avisos): self
    {
        return new self($slug, true, $blogs, $situacao, $avisos);
    }

    public static function pulado(string $slug): self
    {
        return new self($slug, false, null, null, []);
    }
}
