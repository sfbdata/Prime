<?php

declare(strict_types=1);

namespace App\Blog\Enum;

/**
 * Situação do artigo no ciclo da spec painel-artigos-blogs (seção 1): rascunho, agendado, publicado e despublicado.
 * As transições moram em {@see \App\Blog\Entity\ArtigoDoBlog}.
 */
enum SituacaoDoArtigo: string
{
    case Rascunho = 'rascunho';
    case Agendado = 'agendado';
    case Publicado = 'publicado';
    case Despublicado = 'despublicado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Agendado => 'Agendado',
            self::Publicado => 'Publicado',
            self::Despublicado => 'Despublicado',
        };
    }
}
