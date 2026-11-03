<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/** Exclui um artigo que não está no ar (artigo publicado pede despublicar antes; a regra mora na entidade). */
final class ExcluirArtigoDoBlogUseCase
{
    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    /** @throws \DomainException artigo publicado */
    public function executar(int $id): void
    {
        $artigo = $this->artigos->porId($id) ?? throw ArtigoDoBlogNaoEncontrado::comId($id);
        $artigo->exigirExcluivel();
        $this->escopoDePlataforma->executar(fn () => $this->artigos->remover($artigo, flush: true));
    }
}
