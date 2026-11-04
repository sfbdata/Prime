<?php

declare(strict_types=1);

namespace App\Blog\Service;

use App\Blog\Armazenamento\ChavesDoBlog;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\ImagemDoArtigo;
use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Exception\PacoteDosBlogsVazio;
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
 * Leva TODOS os artigos publicados (o build gera o blog inteiro e o `rsync --delete` tira do ar o que saiu), cada um
 * pela VERSÃO PUBLICADA congelada no Publicar ({@see VersaoPublicadaDoArtigo}, D7): o `.md` de cada pasta e as imagens
 * pelos ids guardados, lidas do armazenamento por chave, em stream. Nunca o texto atual: uma alteração guardada e não
 * publicada não vai ao ar quando outro artigo é publicado.
 *
 * As quatro pastas (`artigos/{blog,blog-gratis}` e as `imagens/` de cada uma) existem sempre, mesmo vazias. Nenhum
 * artigo publicado nos dois blogs é recusado ({@see PacoteDosBlogsVazio}): o build tiraria tudo do ar.
 *
 * Escrita atômica: tudo é montado em `.{id}.montando/` e renomeado no fim ({@see PastaDosPedidos}). Uma falha no meio
 * apaga a montagem e sobe a exceção.
 */
final class MontadorDoPacoteDosBlogs
{
    public const VERSAO = 1;

    /** As pastas dos blogs no pacote, na ordem do `pedido.json`. */
    public const PASTAS = ['blog', 'blog-gratis'];

    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly ImagemDoArtigoRepository $imagens,
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
            foreach (self::PASTAS as $pastaDoBlog) {
                $this->pasta->criarPastaNaMontagem($id, 'artigos/' . $pastaDoBlog . '/imagens');
            }
            foreach ($this->artigos->publicados() as $artigo) {
                foreach ($this->gravarArtigo($id, $artigo) as $pastaDoBlog => $slug) {
                    $slugs[$pastaDoBlog][] = $slug;
                }
            }
            if (array_merge(...array_values($slugs)) === []) {
                throw new PacoteDosBlogsVazio();
            }
            $this->pasta->gravarNaMontagem($id, 'pedido.json', $this->pedidoJson($publicacao, $slugs));
            $this->pasta->concluirMontagem($id);
        } catch (\Throwable $e) {
            $this->pasta->descartarMontagem($id);

            throw $e;
        }

        return $slugs;
    }

    /** @return array<string, string> pasta do blog => slug com que o artigo entrou */
    private function gravarArtigo(int $id, ArtigoDoBlog $artigo): array
    {
        $versao = $artigo->getVersaoPublicada() ?? throw new \RuntimeException(sprintf(
            'O artigo "%s" está publicado sem a versão publicada guardada: publique-o de novo pelo editor.',
            $artigo->getSlug(),
        ));
        $imagens = $this->imagensDaVersao($artigo, $versao['imagens']);
        $entrou = [];
        foreach ($versao['arquivos'] as $pastaDoBlog => $md) {
            if (!\in_array($pastaDoBlog, self::PASTAS, true)) {
                throw new \RuntimeException(sprintf('Pasta "%s" desconhecida na versão publicada de "%s".', $pastaDoBlog, $versao['slug']));
            }
            $this->pasta->gravarNaMontagem($id, 'artigos/' . $pastaDoBlog . '/' . $versao['slug'] . '.md', $md);
            foreach ($imagens as $imagem) {
                $this->copiarImagem($id, $pastaDoBlog, $imagem);
            }
            $entrou[$pastaDoBlog] = $versao['slug'];
        }

        return $entrou;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<ImagemDoArtigo>
     */
    private function imagensDaVersao(ArtigoDoBlog $artigo, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $imagens = array_values(array_filter($this->imagens->doArtigo($artigo), static fn (ImagemDoArtigo $i): bool => \in_array((int) $i->getId(), $ids, true)));
        if (\count($imagens) !== \count($ids)) {
            throw new \RuntimeException(sprintf('Uma imagem da versão publicada de "%s" não existe mais: publique o artigo de novo.', $artigo->getSlug()));
        }

        return $imagens;
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
