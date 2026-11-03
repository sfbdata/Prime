<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Armazenamento\ChavesDoBlog;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * Exclui um artigo que não está no ar (artigo publicado pede despublicar antes; a regra mora na entidade).
 *
 * As imagens do artigo (corpo e capa) saem junto: as linhas no mesmo flush, e os arquivos só DEPOIS do COMMIT
 * ({@see RemocaoAposTransacao}, INV-6). As chaves são montadas antes do flush, enquanto as imagens ainda existem.
 */
final class ExcluirArtigoDoBlogUseCase
{
    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly ImagemDoArtigoRepository $imagens,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    /** @throws \DomainException artigo publicado */
    public function executar(int $id): void
    {
        $artigo = $this->artigos->porId($id) ?? throw ArtigoDoBlogNaoEncontrado::comId($id);
        $artigo->exigirExcluivel();
        $imagens = $this->imagens->doArtigo($artigo);
        $chaves = array_map(ChavesDoBlog::imagem(...), $imagens);
        // Remove as linhas pelo ORM também: carregadas, não podem ficar no UnitOfWork apontando para o artigo removido.
        foreach ($imagens as $imagem) {
            $this->imagens->remover($imagem);
        }

        $this->escopoDePlataforma->executar(fn () => $this->artigos->remover($artigo, flush: true));
        $this->remocao->remover($chaves, 'blog_artigo_excluido');
    }
}
