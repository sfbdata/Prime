<?php

declare(strict_types=1);

namespace App\Blog\Service;

use App\Blog\Entity\ArtigoDoBlog;
use Symfony\Component\Yaml\Yaml;

/**
 * Transforma o {@see ArtigoDoBlog} no conteúdo do `.md` que o build dos sites lê (spec painel-artigos-blogs, seção 6):
 * frontmatter YAML com os nomes e a ordem do modelo de `blog-gratis/ARTIGOS.md` (e do artigo real
 * `controle-de-prazos-processuais.md`), datas em AAAA-MM-DD, e o `corpoMarkdown` logo abaixo.
 *
 * Campos opcionais (revisor, atualizadoEm, imagem, imagemAlt, destaque) só saem quando têm valor, como no modelo.
 * `rascunho` não sai: o pacote do build (P4) só leva artigos publicados.
 *
 * D5 ("os dois"): o mesmo `.md` vai para cada pasta de {@see blogsDeDestino()}. Na cópia do `blog-gratis`, quem monta o
 * pacote (P4, seção 6.1) passa `$canonical` com o endereço do artigo no Blog BlueJus ({@see canonicalNoBlog()}), e ele
 * sai como a última chave do frontmatter. Sem `$canonical`, a chave não sai.
 *
 * A imagem de compartilhamento sai com o caminho relativo que o builder vai usar (`./imagens/{id}.{ext}`), recebido
 * em `$caminhoDaImagem`: quem monta o pacote sabe qual ImagemDoArtigo é a capa. Sem o caminho, imagem e alt não saem.
 */
final class ExportadorDeArtigo
{
    public function exportar(ArtigoDoBlog $artigo, ?string $caminhoDaImagem = null, ?string $canonical = null): string
    {
        $frontmatter = $this->frontmatter($artigo, $caminhoDaImagem);
        if ($canonical !== null) {
            $frontmatter['canonical'] = $canonical;
        }
        $yaml = Yaml::dump(
            $frontmatter,
            4,
            2,
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_COMPACT_NESTED_MAPPING | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        );

        return "---\n" . $this->datasSemAspas($yaml) . "---\n\n" . ltrim($artigo->getCorpoMarkdown());
    }

    /**
     * Pastas dos projetos Astro (no repositório dos sites) que recebem este artigo.
     *
     * @return list<string>
     */
    public function blogsDeDestino(ArtigoDoBlog $artigo): array
    {
        return $artigo->getBlogs()->pastasDosSites();
    }

    /** Endereço do artigo no Blog BlueJus: o `canonical` da cópia do grátis de um artigo "os dois" (D5). */
    public function canonicalNoBlog(ArtigoDoBlog $artigo): string
    {
        return sprintf('https://bluejus.com.br/blog/%s/%s', $artigo->getCategoria(), $artigo->getSlug());
    }

    /** @return array<string, mixed> */
    private function frontmatter(ArtigoDoBlog $artigo, ?string $caminhoDaImagem): array
    {
        $dados = [
            'titulo' => $artigo->getTitulo(),
            'tituloCurto' => $artigo->getTituloCurto(),
            'tituloSeo' => $artigo->getTituloSeo(),
            'descricao' => $artigo->getDescricao(),
            'linhaFina' => $artigo->getLinhaFina(),
            'resumo' => $artigo->getResumo(),
            'slug' => $artigo->getSlug(),
            'categoria' => $artigo->getCategoria(),
        ];
        // Spec, seção 11: a chave sai logo depois de `categoria` e só quando há marcadores.
        if ($artigo->getMarcadores() !== []) {
            $dados['marcadores'] = $artigo->getMarcadores();
        }
        $dados['autor'] = $artigo->getAutor();
        if ($artigo->getRevisorNome() !== null && $artigo->getRevisorOab() !== null) {
            $dados['revisor'] = ['nome' => $artigo->getRevisorNome(), 'oab' => $artigo->getRevisorOab()];
        }

        return $dados + $this->datasELeitura($artigo) + $this->imagemEDestaque($artigo, $caminhoDaImagem) + [
            'referencias' => $artigo->getReferencias(),
            'relacionados' => $artigo->getRelacionados(),
        ];
    }

    /** @return array<string, string> */
    private function datasELeitura(ArtigoDoBlog $artigo): array
    {
        $publicadoEm = $artigo->getPublicadoEm() ?? $artigo->getPublicarEm();
        if ($publicadoEm === null) {
            throw new \LogicException(sprintf('O artigo "%s" não tem data de publicação para exportar.', $artigo->getSlug()));
        }

        $dados = ['publicadoEm' => $publicadoEm->format('Y-m-d')];
        if ($artigo->getAtualizadoEm() !== null) {
            $dados['atualizadoEm'] = $artigo->getAtualizadoEm()->format('Y-m-d');
        }

        return $dados + ['leitura' => $artigo->getLeitura()];
    }

    /** @return array<string, string|bool> */
    private function imagemEDestaque(ArtigoDoBlog $artigo, ?string $caminhoDaImagem): array
    {
        $dados = [];
        if ($caminhoDaImagem !== null && $artigo->getImagemChave() !== null) {
            $dados['imagem'] = $caminhoDaImagem;
            $dados['imagemAlt'] = (string) $artigo->getImagemAlt();
        }
        if ($artigo->isDestaque()) {
            $dados['destaque'] = true;
        }

        return $dados;
    }

    /** O dump do symfony/yaml põe aspas nas datas; o modelo as escreve sem aspas (o YAML lê como data). */
    private function datasSemAspas(string $yaml): string
    {
        return (string) preg_replace("/^(publicadoEm|atualizadoEm): '(\\d{4}-\\d{2}-\\d{2})'$/m", '$1: $2', $yaml);
    }
}
