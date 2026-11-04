<?php

declare(strict_types=1);

namespace App\Blog\DTO;

use App\Blog\Entity\PublicacaoDosBlogs;

/** Uma geração dos blogs para a faixa e o histórico da lista de artigos (spec painel-artigos-blogs, seção 2.1). */
final readonly class PublicacaoDosBlogsOutput
{
    /** @param array<string, list<string>>|null $artigos */
    public function __construct(
        public int $id,
        public string $pedidaEm,
        public ?string $pedidaPor,
        public string $motivo,
        public string $motivoRotulo,
        public string $situacao,
        public string $situacaoRotulo,
        public ?string $iniciadaEm,
        public ?string $terminadaEm,
        public ?string $commit,
        public ?string $log,
        public ?array $artigos,
    ) {
    }

    public static function fromEntity(PublicacaoDosBlogs $publicacao): self
    {
        return new self(
            id: (int) $publicacao->getId(),
            pedidaEm: $publicacao->getPedidaEm()->format(\DATE_ATOM),
            pedidaPor: $publicacao->getPedidaPor()?->getFullName(),
            motivo: $publicacao->getMotivo()->value,
            motivoRotulo: $publicacao->getMotivo()->rotulo(),
            situacao: $publicacao->getSituacao()->value,
            situacaoRotulo: $publicacao->getSituacao()->rotulo(),
            iniciadaEm: $publicacao->getIniciadaEm()?->format(\DATE_ATOM),
            terminadaEm: $publicacao->getTerminadaEm()?->format(\DATE_ATOM),
            commit: $publicacao->getCommitDosSites(),
            log: $publicacao->getLog(),
            artigos: $publicacao->getArtigos(),
        );
    }
}
