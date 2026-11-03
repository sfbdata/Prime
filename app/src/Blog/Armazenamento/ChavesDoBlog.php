<?php

declare(strict_types=1);

namespace App\Blog\Armazenamento;

use App\Blog\Entity\ImagemDoArtigo;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * Traduz a imagem de um artigo dos blogs em {@see ChaveDeArquivo} (spec painel-artigos-blogs, seção 4).
 *
 * Escopo GLOBAL (D1), como a imagem da campanha do Comercial: o artigo é conteúdo da plataforma (só o Master escreve),
 * não de um escritório, e a purga de escritório não leva a imagem. Quem decide quem lê é a rota: envio e entrega exigem
 * `ROLE_SUPER_ADMIN`, e a imagem é procurada pelo id antes de montar a chave (INV-5).
 *
 * A imagem nova não recebe a {@see ImagemDoArtigo}: a linha só nasce depois da gravação, com o nome que o storage
 * cunhou (D8), e o escopo é global de qualquer jeito, então não há dona a consultar.
 */
final class ChavesDoBlog
{
    private function __construct()
    {
    }

    public static function imagem(ImagemDoArtigo $imagem): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::BLOG_IMAGEM, $imagem->getChave());
    }

    /** Imagem nova: o nome é cunhado pelo storage (D8); não há escritório a consultar (D1). */
    public static function novaImagem(string $extensao): NovoArquivo
    {
        return new NovoArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::BLOG_IMAGEM, $extensao);
    }
}
