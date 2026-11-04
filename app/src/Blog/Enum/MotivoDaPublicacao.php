<?php

declare(strict_types=1);

namespace App\Blog\Enum;

/**
 * Por que os blogs foram gerados de novo (spec painel-artigos-blogs, seção 1, `PublicacaoDosBlogs`): o Master publicou
 * ou despublicou um artigo, o agendador pôs no ar um artigo agendado (P5), ou alguém pediu pelo console
 * (`blog:gerar-de-novo`, mudança de código dos blogs).
 */
enum MotivoDaPublicacao: string
{
    case Publicar = 'publicar';
    case Agendado = 'agendado';
    case Despublicar = 'despublicar';
    case Manual = 'manual';

    public function rotulo(): string
    {
        return match ($this) {
            self::Publicar => 'Publicação de artigo',
            self::Agendado => 'Artigo agendado',
            self::Despublicar => 'Artigo despublicado',
            self::Manual => 'Geração pedida pela equipe',
        };
    }
}
