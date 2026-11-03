<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\DTO\ArtigoParaEditorOutput;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;

/** Dados da página do editor: o artigo (ou o rascunho em branco), com o título de cada relacionado. */
final class CarregarArtigoParaEditorUseCase
{
    public function __construct(private readonly ArtigoDoBlogRepository $artigos)
    {
    }

    public function executar(?int $id): ArtigoParaEditorOutput
    {
        if ($id === null) {
            return ArtigoParaEditorOutput::vazio();
        }

        $artigo = $this->artigos->porId($id) ?? throw ArtigoDoBlogNaoEncontrado::comId($id);

        return ArtigoParaEditorOutput::fromEntity($artigo, $this->artigos->titulosPorSlug($artigo->getRelacionados()));
    }
}
