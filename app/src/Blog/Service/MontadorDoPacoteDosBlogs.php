<?php

declare(strict_types=1);

namespace App\Blog\Service;

use App\Blog\Armazenamento\ChavesDoBlog;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\ImagemDoArtigo;
use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;

/**
 * Monta o pacote de um pedido de geração dos blogs, no formato do contrato da spec painel-artigos-blogs, seção 6.1
 * (versão {@see self::VERSAO}):
 *
 * ```
 * {id}/pedido.json
 * {id}/artigos/blog/{slug}.md          {id}/artigos/blog/imagens/{imagem}.{ext}
 * {id}/artigos/blog-gratis/{slug}.md   {id}/artigos/blog-gratis/imagens/{imagem}.{ext}
 * ```
 *
 * Leva TODOS os artigos publicados (o build gera o blog inteiro e o `rsync --delete` tira do ar o que saiu). Cada
 * artigo vai para as pastas de {@see ExportadorDeArtigo::blogsDeDestino()}; a cópia do grátis de um artigo "os dois"
 * leva o `canonical` do Blog BlueJus (D5). As imagens (as citadas no corpo como `./imagens/{id}.{ext}` e a capa) saem
 * do armazenamento por chave, em stream, para a pasta `imagens/` ao lado do `.md`.
 *
 * Escrita atômica: tudo é montado em `.{id}.montando/` e renomeado no fim ({@see PastaDosPedidos}). Uma falha no meio
 * apaga a montagem e sobe a exceção.
 */
final class MontadorDoPacoteDosBlogs
{
    public const VERSAO = 1;

    /** As pastas dos blogs no pacote, na ordem do `pedido.json`. */
    public const PASTAS = ['blog', 'blog-gratis'];

    private const IMAGEM_NO_CORPO = '#\./imagens/(\d+)\.(?:jpg|png|webp)\b#';

    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly ImagemDoArtigoRepository $imagens,
        private readonly ExportadorDeArtigo $exportador,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly PastaDosPedidos $pasta,
    ) {
    }

    /** @return array<string, list<string>> pasta do blog => slugs que entraram no pacote */
    public function montar(PublicacaoDosBlogs $publicacao): array
    {
        $id = (int) $publicacao->getId();
        $slugs = array_fill_keys(self::PASTAS, []);
        $this->pasta->comecarMontagem($id);
        try {
            foreach ($this->artigos->publicados() as $artigo) {
                foreach ($this->gravarArtigo($id, $artigo) as $pastaDoBlog) {
                    $slugs[$pastaDoBlog][] = $artigo->getSlug();
                }
            }
            $this->pasta->gravarNaMontagem($id, 'pedido.json', $this->pedidoJson($publicacao, $slugs));
            $this->pasta->concluirMontagem($id);
        } catch (\Throwable $e) {
            $this->pasta->descartarMontagem($id);

            throw $e;
        }

        return $slugs;
    }

    /** @return list<string> as pastas dos blogs em que o artigo entrou */
    private function gravarArtigo(int $id, ArtigoDoBlog $artigo): array
    {
        $usadas = $this->imagensUsadas($artigo);
        $capa = $usadas[(string) $artigo->getImagemChave()] ?? null;
        $caminhoDaCapa = $capa === null ? null : './imagens/' . $capa->getId() . '.' . $capa->extensao();
        $pastas = $this->exportador->blogsDeDestino($artigo);
        foreach ($pastas as $pastaDoBlog) {
            $canonical = ('blog-gratis' === $pastaDoBlog && BlogsDoArtigo::Ambos === $artigo->getBlogs())
                ? $this->exportador->canonicalNoBlog($artigo)
                : null;
            $md = $this->exportador->exportar($artigo, $caminhoDaCapa, $canonical);
            $this->pasta->gravarNaMontagem($id, 'artigos/' . $pastaDoBlog . '/' . $artigo->getSlug() . '.md', $md);
            foreach ($usadas as $imagem) {
                $this->copiarImagem($id, $pastaDoBlog, $imagem);
            }
        }

        return $pastas;
    }

    /**
     * As imagens do artigo que o pacote leva: as citadas no corpo e a capa. Imagem citada que não é do artigo não entra
     * (o conversor já a tira do HTML ao salvar).
     *
     * @return array<string, ImagemDoArtigo> id da imagem (texto) => imagem
     */
    private function imagensUsadas(ArtigoDoBlog $artigo): array
    {
        preg_match_all(self::IMAGEM_NO_CORPO, $artigo->getCorpoMarkdown(), $achados);
        $ids = $achados[1];
        if ($artigo->getImagemChave() !== null) {
            $ids[] = $artigo->getImagemChave();
        }
        $usadas = [];
        foreach ($this->imagens->doArtigo($artigo) as $imagem) {
            if (\in_array((string) $imagem->getId(), $ids, true)) {
                $usadas[(string) $imagem->getId()] = $imagem;
            }
        }

        return $usadas;
    }

    private function copiarImagem(int $id, string $pastaDoBlog, ImagemDoArtigo $imagem): void
    {
        $origem = $this->armazenamento->abrir(ChavesDoBlog::imagem($imagem));
        try {
            $this->pasta->copiarParaMontagem($id, sprintf('artigos/%s/imagens/%d.%s', $pastaDoBlog, $imagem->getId(), $imagem->extensao()), $origem);
        } finally {
            if (\is_resource($origem)) {
                fclose($origem);
            }
        }
    }

    /** @param array<string, list<string>> $slugs */
    private function pedidoJson(PublicacaoDosBlogs $publicacao, array $slugs): string
    {
        return json_encode([
            'versao' => self::VERSAO,
            'id' => $publicacao->getId(),
            'pedidaEm' => $publicacao->getPedidaEm()->format(\DATE_ATOM),
            'motivo' => $publicacao->getMotivo()->value,
            'artigos' => $slugs,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }
}
