<?php

declare(strict_types=1);

namespace App\Tests\Blog;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;

/** Artigos prontos para os testes do domínio Blog. */
final class ArtigosDeTeste
{
    /** O controle-de-prazos-processuais.md do repositório dos sites, já publicado e atualizado. */
    public static function controleDePrazos(string $corpoMarkdown = "Texto.\n"): ArtigoDoBlog
    {
        $artigo = new ArtigoDoBlog('controle-de-prazos-processuais', BlogsDoArtigo::Normal, null, new \DateTimeImmutable('2026-10-20 10:00'));
        $artigo->editarTextos(
            'Controle de prazos processuais: como montar uma rotina que não depende da memória',
            'Controle de prazos processuais',
            'Controle de prazos processuais: rotina para o escritório',
            'Como contar prazos em dias úteis, lidar com o recesso e a intimação eletrônica, e uma rotina em quatro etapas para não perder prazos.',
            'O que a lei estabelece sobre a contagem dos prazos e um fluxo de quatro etapas para que nenhuma intimação fique sem responsável no escritório.',
            'Contagem em dias úteis, recesso forense, intimação eletrônica e um fluxo de quatro etapas para o escritório não perder prazo.',
        );
        $artigo->classificar('prazos-processuais', 'equipe-editorial-bluejus', null, null, '9 min', true);
        $artigo->definirReferencias([
            ['texto' => 'BRASIL. Lei nº 13.105, de 16 de março de 2015. Código de Processo Civil, arts. 219, 220 e 224.', 'url' => 'http://www.planalto.gov.br/ccivil_03/_ato2015-2018/2015/lei/l13105.htm', 'rotulo' => 'planalto.gov.br'],
            ['texto' => 'BRASIL. Lei nº 11.419, de 19 de dezembro de 2006. Dispõe sobre a informatização do processo judicial, art. 5º.', 'url' => 'http://www.planalto.gov.br/ccivil_03/_ato2004-2006/2006/lei/l11419.htm', 'rotulo' => 'planalto.gov.br'],
        ]);
        $artigo->definirCorpo('<p>Texto.</p>', $corpoMarkdown);

        $artigo->agendar(new \DateTimeImmutable('2026-10-28 09:00'), new \DateTimeImmutable('2026-10-27 18:00'));
        $artigo->publicar(new \DateTimeImmutable('2026-10-28 09:05'));
        $artigo->editarTextos($artigo->getTitulo(), $artigo->getTituloCurto(), $artigo->getTituloSeo(), $artigo->getDescricao(), $artigo->getLinhaFina(), $artigo->getResumo() . ' ');
        $artigo->registrarAlteracao(null, new \DateTimeImmutable('2026-10-30 08:00'));
        $artigo->publicar(new \DateTimeImmutable('2026-10-30 08:10'));
        // A versão congelada no Publicar (P4, D7); o conteúdo exato não importa aos testes que usam este artigo.
        $artigo->registrarVersaoPublicada(['blog' => "---\nslug: controle-de-prazos-processuais\n---\n\n" . $corpoMarkdown], []);

        return $artigo;
    }

    /** Rascunho sem data, com tudo o que o zod pede para ir ao ar (Publicar agora). */
    public static function rascunhoCompleto(string $slug = 'rascunho-completo', BlogsDoArtigo $blogs = BlogsDoArtigo::Normal): ArtigoDoBlog
    {
        $artigo = new ArtigoDoBlog($slug, $blogs, null, new \DateTimeImmutable('2026-11-01 10:00'));
        $artigo->editarTextos('Título completo', 'Título curto', 'Título SEO', 'Descrição do artigo.', 'Linha fina do artigo.', 'Resumo do artigo.');
        $artigo->classificar('prazos-processuais', 'equipe-editorial-bluejus', null, null, '3 min', false);
        $artigo->definirCorpo('<p>Texto.</p>', "Texto.\n");

        return $artigo;
    }
}
