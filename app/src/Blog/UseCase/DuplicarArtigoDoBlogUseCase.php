<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Entity\Auth\User;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * "Duplicar" da lista: cria um RASCUNHO novo com os textos do artigo, slug `<slug>-copia` (com número se já existir).
 * As imagens do corpo e a capa não são copiadas (cada artigo tem as suas, spec seção 4): o editor avisa.
 */
final class DuplicarArtigoDoBlogUseCase
{
    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(int $id, ?User $ator): ArtigoDoBlog
    {
        $origem = $this->artigos->porId($id) ?? throw ArtigoDoBlogNaoEncontrado::comId($id);
        $copia = new ArtigoDoBlog($this->slugLivre($origem->getSlug()), $origem->getBlogs(), $ator, $this->relogio->now());
        $copia->editarTextos($origem->getTitulo(), $origem->getTituloCurto(), $origem->getTituloSeo(), $origem->getDescricao(), $origem->getLinhaFina(), $origem->getResumo());
        $copia->classificar($origem->getCategoria(), $origem->getAutor(), $origem->getRevisorNome(), $origem->getRevisorOab(), $origem->getLeitura(), false);
        $copia->definirReferencias($origem->getReferencias());
        $copia->definirRelacionados($origem->getRelacionados());
        $copia->definirMarcadores($origem->getMarcadores());
        $copia->definirCorpo($this->semImagens($origem->getCorpoHtml()), $this->semImagens($origem->getCorpoMarkdown()));

        $this->escopoDePlataforma->executar(fn () => $this->artigos->salvar($copia, flush: true));

        return $copia;
    }

    private function slugLivre(string $slug): string
    {
        // Sem o rtrim, um corte em cima de um hífen geraria `--copia`, que o slug não aceita.
        $base = rtrim(mb_substr($slug, 0, 110), '-') . '-copia';
        $candidato = $base;
        for ($n = 2; $this->artigos->existeSlug($candidato); ++$n) {
            $candidato = $base . '-' . $n;
        }

        return $candidato;
    }

    /** Tira as imagens do corpo (HTML `<img>` e Markdown `![alt](...)`): o arquivo pertence ao artigo de origem. */
    private function semImagens(string $texto): string
    {
        return (string) preg_replace(['/<img\b[^>]*>/i', '/!\[[^\]]*\]\([^)]*\)/'], '', $texto);
    }
}
