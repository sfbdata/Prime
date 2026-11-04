<?php

declare(strict_types=1);

namespace App\Blog\Service;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\ImagemDoArtigo;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Repository\ImagemDoArtigoRepository;

/**
 * Congela a versão publicada de um artigo, no instante do Publicar (D7; spec painel-artigos-blogs, seções 1 e 6.1).
 *
 * Exporta o `.md` de cada pasta dos sites pelo {@see ExportadorDeArtigo} (a cópia do `blog-gratis` de um artigo "os
 * dois" leva o canonical do Blog BlueJus, D5) e guarda os ids das imagens que esse `.md` usa: as citadas no corpo como
 * `./imagens/{id}.{ext}` e a capa. O pacote do builder monta só com isto ({@see MontadorDoPacoteDosBlogs}).
 *
 * Imagem citada que não é do artigo não entra (o conversor já a tira do HTML ao salvar). Artigo ainda sem id (a
 * importação da P2) não tem imagens.
 */
final class VersaoPublicadaDoArtigo
{
    private const IMAGEM_NO_CORPO = '#\./imagens/(\d+)\.(?:jpg|png|webp)\b#';

    public function __construct(
        private readonly ImagemDoArtigoRepository $imagens,
        private readonly ExportadorDeArtigo $exportador,
    ) {
    }

    /** Chame logo depois de {@see ArtigoDoBlog::publicar()}: o `.md` leva as datas da publicação. */
    public function congelar(ArtigoDoBlog $artigo): void
    {
        $usadas = $this->imagensUsadas($artigo);
        $capa = $usadas[(string) $artigo->getImagemChave()] ?? null;
        $caminhoDaCapa = $capa === null ? null : './imagens/' . $capa->getId() . '.' . $capa->extensao();

        $arquivos = [];
        foreach ($this->exportador->blogsDeDestino($artigo) as $pasta) {
            $canonical = ('blog-gratis' === $pasta && BlogsDoArtigo::Ambos === $artigo->getBlogs())
                ? $this->exportador->canonicalNoBlog($artigo)
                : null;
            $arquivos[$pasta] = $this->exportador->exportar($artigo, $caminhoDaCapa, $canonical);
        }

        $artigo->registrarVersaoPublicada($arquivos, array_map(static fn (string $id): int => (int) $id, array_keys($usadas)));
    }

    /** @return array<string, ImagemDoArtigo> id da imagem (texto) => imagem */
    private function imagensUsadas(ArtigoDoBlog $artigo): array
    {
        if ($artigo->getId() === null) {
            return [];
        }
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
}
