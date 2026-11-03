<?php

declare(strict_types=1);

namespace App\Blog\Enum;

/**
 * Em qual blog o artigo sai (spec painel-artigos-blogs, seção 1). `ambos` publica o mesmo texto nos dois
 * endereços (D5): o canonical para o Blog BlueJus é trabalho do repositório dos sites (P4).
 */
enum BlogsDoArtigo: string
{
    case Normal = 'normal';
    case Gratis = 'gratis';
    case Ambos = 'ambos';

    /**
     * Pastas dos projetos Astro que recebem o artigo, no repositório dos sites.
     *
     * @return list<string>
     */
    public function pastasDosSites(): array
    {
        return match ($this) {
            self::Normal => ['blog'],
            self::Gratis => ['blog-gratis'],
            self::Ambos => ['blog', 'blog-gratis'],
        };
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::Normal => 'Blog BlueJus',
            self::Gratis => 'Blog BlueJus Grátis',
            self::Ambos => 'Os dois',
        };
    }
}
