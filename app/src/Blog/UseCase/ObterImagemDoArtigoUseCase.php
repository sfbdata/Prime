<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Entity\ImagemDoArtigo;
use App\Blog\Exception\ImagemDoArtigoNaoEncontrada;
use App\Blog\Repository\ImagemDoArtigoRepository;

/**
 * A imagem de um artigo para o editor mostrar (`GET /master/blog/imagens/{id}`, spec painel-artigos-blogs, seção 4).
 * Só o Master chega aqui (a rota exige ROLE_SUPER_ADMIN); a imagem é global, sem escritório a conferir. O controller monta
 * a chave pela {@see \App\Blog\Armazenamento\ChavesDoBlog} e entrega inline.
 */
final class ObterImagemDoArtigoUseCase
{
    public function __construct(private readonly ImagemDoArtigoRepository $imagens)
    {
    }

    /** @throws ImagemDoArtigoNaoEncontrada */
    public function executar(int $id): ImagemDoArtigo
    {
        return $this->imagens->porId($id) ?? throw ImagemDoArtigoNaoEncontrada::comId($id);
    }
}
