<?php

declare(strict_types=1);

namespace App\Blog\Enum;

/**
 * Situação de uma geração dos blogs (spec painel-artigos-blogs, seções 1 e 6.1): na fila (o pacote ainda vai ser
 * montado ou o builder ainda não começou), gerando (o builder escreveu `iniciado`), no ar ou falhou (o builder
 * escreveu `resultado.json`, ou não respondeu a tempo). As transições moram em {@see \App\Blog\Entity\PublicacaoDosBlogs}.
 */
enum SituacaoDaPublicacao: string
{
    case NaFila = 'na_fila';
    case Gerando = 'gerando';
    case NoAr = 'no_ar';
    case Falhou = 'falhou';

    public function rotulo(): string
    {
        return match ($this) {
            self::NaFila => 'Na fila',
            self::Gerando => 'Gerando',
            self::NoAr => 'No ar',
            self::Falhou => 'Falhou',
        };
    }

    public function estaAberta(): bool
    {
        return self::NaFila === $this || self::Gerando === $this;
    }
}
