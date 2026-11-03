<?php

declare(strict_types=1);

namespace App\Blog\DTO;

use App\Blog\Entity\ArtigoDoBlog;

/** O artigo depois de salvo, para a tela do editor (P2). */
final readonly class ArtigoDoBlogOutput
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $titulo,
        public string $blogs,
        public string $situacao,
        public bool $alteracoesPendentes,
        public string $leitura,
        public string $corpoHtml,
        public \DateTimeImmutable $alteradoEm,
    ) {
    }

    public static function fromEntity(ArtigoDoBlog $artigo): self
    {
        return new self(
            id: (int) $artigo->getId(),
            slug: $artigo->getSlug(),
            titulo: $artigo->getTitulo(),
            blogs: $artigo->getBlogs()->value,
            situacao: $artigo->getSituacao()->value,
            alteracoesPendentes: $artigo->temAlteracoesPendentes(),
            leitura: $artigo->getLeitura(),
            corpoHtml: $artigo->getCorpoHtml(),
            alteradoEm: $artigo->getAlteradoEm(),
        );
    }
}
