<?php

declare(strict_types=1);

namespace App\Blog\DTO;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Service\CatalogoDoBlog;

/** Tudo o que a página do editor precisa de um artigo (vira JSON na página; nunca o Markdown, que é gerado). */
final readonly class ArtigoParaEditorOutput
{
    /**
     * @param list<array{texto: string, url: string, rotulo: string}> $referencias
     * @param list<array{slug: string, titulo: string}>               $relacionados
     * @param list<string>                                            $marcadores
     */
    public function __construct(
        public ?int $id,
        public string $slug,
        public string $blogs,
        public string $titulo,
        public string $tituloCurto,
        public string $tituloSeo,
        public string $descricao,
        public string $linhaFina,
        public string $resumo,
        public string $categoria,
        public string $autor,
        public string $revisorNome,
        public string $revisorOab,
        public string $leitura,
        public bool $destaque,
        public array $referencias,
        public array $relacionados,
        public array $marcadores,
        public string $corpoHtml,
        public string $situacao,
        public string $situacaoRotulo,
        public bool $alteracoesPendentes,
        public bool $jaFoiAoAr,
    ) {
    }

    /** O rascunho em branco da tela "Novo artigo". */
    public static function vazio(): self
    {
        return new self(null, '', 'normal', '', '', '', '', '', '', '', (string) array_key_first(CatalogoDoBlog::AUTORES), '', '', '', false, [], [], [], '', 'rascunho', 'Rascunho', false, false);
    }

    /** @param array<string, string> $titulosDosRelacionados slug => título, dos artigos que existem */
    public static function fromEntity(ArtigoDoBlog $artigo, array $titulosDosRelacionados): self
    {
        $relacionados = [];
        foreach ($artigo->getRelacionados() as $slug) {
            $relacionados[] = ['slug' => $slug, 'titulo' => $titulosDosRelacionados[$slug] ?? $slug];
        }

        return new self(
            id: (int) $artigo->getId(),
            slug: $artigo->getSlug(),
            blogs: $artigo->getBlogs()->value,
            titulo: $artigo->getTitulo(),
            tituloCurto: $artigo->getTituloCurto(),
            tituloSeo: $artigo->getTituloSeo(),
            descricao: $artigo->getDescricao(),
            linhaFina: $artigo->getLinhaFina(),
            resumo: $artigo->getResumo(),
            categoria: $artigo->getCategoria(),
            autor: $artigo->getAutor(),
            revisorNome: (string) $artigo->getRevisorNome(),
            revisorOab: (string) $artigo->getRevisorOab(),
            leitura: $artigo->getLeitura(),
            destaque: $artigo->isDestaque(),
            referencias: $artigo->getReferencias(),
            relacionados: $relacionados,
            marcadores: $artigo->getMarcadores(),
            corpoHtml: $artigo->getCorpoHtml(),
            situacao: $artigo->getSituacao()->value,
            situacaoRotulo: $artigo->getSituacao()->rotulo(),
            alteracoesPendentes: $artigo->temAlteracoesPendentes(),
            jaFoiAoAr: \in_array($artigo->getSituacao()->value, ['publicado', 'despublicado'], true),
        );
    }
}
