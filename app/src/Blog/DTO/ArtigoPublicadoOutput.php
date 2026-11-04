<?php

declare(strict_types=1);

namespace App\Blog\DTO;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\PublicacaoDosBlogs;

/** O artigo depois de Publicar ou Despublicar, e o pedido de geração dos blogs que leva a mudança ao site. */
final readonly class ArtigoPublicadoOutput
{
    public function __construct(
        public int $id,
        public string $situacao,
        public string $situacaoRotulo,
        public bool $alteracoesPendentes,
        public PublicacaoDosBlogsOutput $publicacao,
    ) {
    }

    public static function de(ArtigoDoBlog $artigo, PublicacaoDosBlogs $publicacao): self
    {
        return new self(
            id: (int) $artigo->getId(),
            situacao: $artigo->getSituacao()->value,
            situacaoRotulo: $artigo->getSituacao()->rotulo(),
            alteracoesPendentes: $artigo->temAlteracoesPendentes(),
            publicacao: PublicacaoDosBlogsOutput::fromEntity($publicacao),
        );
    }
}
