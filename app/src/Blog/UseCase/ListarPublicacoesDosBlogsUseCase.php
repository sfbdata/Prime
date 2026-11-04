<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\DTO\PublicacaoDosBlogsOutput;
use App\Blog\Repository\PublicacaoDosBlogsRepository;

/** As últimas gerações dos blogs, as mais novas primeiro: a faixa e o histórico da lista (spec, seção 2.1). */
final class ListarPublicacoesDosBlogsUseCase
{
    public const LIMITE = 20;

    public function __construct(private readonly PublicacaoDosBlogsRepository $publicacoes)
    {
    }

    /** @return array{itens: list<PublicacaoDosBlogsOutput>} */
    public function executar(): array
    {
        return ['itens' => array_map(PublicacaoDosBlogsOutput::fromEntity(...), $this->publicacoes->ultimas(self::LIMITE))];
    }
}
