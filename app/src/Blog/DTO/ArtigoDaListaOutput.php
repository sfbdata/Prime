<?php

declare(strict_types=1);

namespace App\Blog\DTO;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Service\CatalogoDoBlog;

/** Uma linha da tabela "Artigos dos blogs" do Painel do Master. */
final readonly class ArtigoDaListaOutput
{
    public function __construct(
        public int $id,
        public string $titulo,
        public string $slug,
        public string $blogs,
        public string $blogsRotulo,
        public string $categoria,
        public bool $destaque,
        public string $situacao,
        public string $situacaoRotulo,
        public ?string $data,
        public bool $alteracoesPendentes,
        public string $caminhoNoSite,
    ) {
    }

    public static function fromEntity(ArtigoDoBlog $artigo): self
    {
        $data = $artigo->getPublicadoEm() ?? $artigo->getPublicarEm();
        $prefixo = $artigo->getBlogs()->value === 'gratis' ? '/gratis' : '/blog';

        return new self(
            id: (int) $artigo->getId(),
            titulo: $artigo->getTitulo() !== '' ? $artigo->getTitulo() : '(sem título)',
            slug: $artigo->getSlug(),
            blogs: $artigo->getBlogs()->value,
            blogsRotulo: $artigo->getBlogs()->rotulo(),
            categoria: CatalogoDoBlog::nomeDaCategoria($artigo->getCategoria()) ?? '',
            destaque: $artigo->isDestaque(),
            situacao: $artigo->getSituacao()->value,
            situacaoRotulo: $artigo->getSituacao()->rotulo(),
            data: $data?->format('Y-m-d\TH:i'),
            alteracoesPendentes: $artigo->temAlteracoesPendentes(),
            caminhoNoSite: $prefixo . '/' . $artigo->getCategoria() . '/' . $artigo->getSlug(),
        );
    }
}
