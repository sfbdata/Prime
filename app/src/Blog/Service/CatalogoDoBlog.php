<?php

declare(strict_types=1);

namespace App\Blog\Service;

/**
 * Categorias e autores dos blogs, copiados do repositório dos sites (sfbdata/sites-bluejus):
 * `blog/src/dados/categorias.ts` e `blog/src/dados/autores.ts` (os mesmos nos dois blogs).
 *
 * D6: a lista continua no código dos sites. Esta cópia PRECISA continuar igual à de lá: o zod do build recusa
 * categoria ou autor que não estejam no `content.config.ts`. Mudou lá, muda aqui no mesmo dia.
 */
final class CatalogoDoBlog
{
    /** slug => nome da categoria, na ordem do site. */
    public const CATEGORIAS = [
        'gestao-de-escritorios' => 'Gestão de Escritórios de Advocacia',
        'tecnologia-juridica' => 'Tecnologia Jurídica e Inteligência Artificial',
        'prazos-processuais' => 'Prazos Processuais e Controle de Processos',
        'organizacao-de-documentos' => 'Organização de Documentos Jurídicos',
        'produtividade' => 'Produtividade e Gestão do Tempo',
        'marketing-juridico' => 'Marketing Jurídico e Ética da OAB',
        'seguranca-digital-e-lgpd' => 'Segurança Digital e LGPD',
        'automacao' => 'Automação na Advocacia',
        'inteligencia-artificial' => 'Inteligência Artificial no Direito',
        'empreendedorismo-juridico' => 'Empreendedorismo Jurídico',
        'gestao-de-equipes' => 'Gestão de Equipes e Escritórios',
        'novidades-do-bluejus' => 'Novidades e Atualizações do BlueJus',
    ];

    /** slug => nome do autor. */
    public const AUTORES = [
        'equipe-editorial-bluejus' => 'Equipe editorial BlueJus',
    ];

    public static function existeCategoria(string $slug): bool
    {
        return isset(self::CATEGORIAS[$slug]);
    }

    public static function existeAutor(string $slug): bool
    {
        return isset(self::AUTORES[$slug]);
    }

    public static function nomeDaCategoria(string $slug): ?string
    {
        return self::CATEGORIAS[$slug] ?? null;
    }

    public static function nomeDoAutor(string $slug): ?string
    {
        return self::AUTORES[$slug] ?? null;
    }
}
