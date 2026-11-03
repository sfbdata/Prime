<?php

declare(strict_types=1);

namespace App\Blog\Service;

use App\Blog\Entity\ArtigoDoBlog;

/**
 * As regras do zod do repositório dos sites (`blog/src/content.config.ts`) em PHP, com mensagens em português, para
 * o build nunca ser o primeiro a recusar um artigo (spec painel-artigos-blogs, seção 2.2).
 *
 * Roda antes de agendar e de publicar. O rascunho não passa por aqui: ele salva incompleto.
 * Além do zod, exige corpo e data de publicação, que um artigo no ar precisa ter.
 *
 * Devolve os erros por campo (nome do campo do artigo => mensagens); lista vazia = pode ir ao ar.
 */
final class ValidadorDoArtigo
{
    public const MAX_TITULO_SEO = 60;
    public const MAX_DESCRICAO = 160;

    private const OBRIGATORIOS = [
        'titulo' => 'Informe o título.',
        'tituloCurto' => 'Informe o título curto.',
        'tituloSeo' => 'Informe o título SEO.',
        'descricao' => 'Informe a meta description.',
        'linhaFina' => 'Informe a linha fina.',
        'resumo' => 'Informe o resumo.',
        'leitura' => 'Informe o tempo de leitura.',
        'corpo' => 'Escreva o texto do artigo.',
    ];

    /** @var array<string, list<string>> */
    private array $erros = [];

    /**
     * @param list<string> $slugsExistentes todos os slugs cadastrados (ArtigoDoBlogRepository::slugsExistentes())
     *
     * @return array<string, list<string>>
     */
    public function validar(ArtigoDoBlog $artigo, array $slugsExistentes): array
    {
        $this->erros = [];
        $this->exigirObrigatorios($artigo);
        $this->exigirTamanhos($artigo);
        $this->exigirSlug($artigo->getSlug());
        $this->exigirCatalogo($artigo);
        $this->exigirRevisor($artigo);
        $this->exigirAltDaImagem($artigo);
        $this->exigirReferencias($artigo->getReferencias());
        $this->exigirRelacionados($artigo, $slugsExistentes);
        if ($artigo->getPublicarEm() === null && $artigo->getPublicadoEm() === null) {
            $this->erro('publicarEm', 'Informe a data de publicação.');
        }

        return $this->erros;
    }

    private function exigirObrigatorios(ArtigoDoBlog $artigo): void
    {
        $valores = [
            'titulo' => $artigo->getTitulo(),
            'tituloCurto' => $artigo->getTituloCurto(),
            'tituloSeo' => $artigo->getTituloSeo(),
            'descricao' => $artigo->getDescricao(),
            'linhaFina' => $artigo->getLinhaFina(),
            'resumo' => $artigo->getResumo(),
            'leitura' => $artigo->getLeitura(),
            'corpo' => $artigo->getCorpoMarkdown(),
        ];
        foreach (self::OBRIGATORIOS as $campo => $mensagem) {
            if (trim($valores[$campo]) === '') {
                $this->erro($campo, $mensagem);
            }
        }
    }

    private function exigirTamanhos(ArtigoDoBlog $artigo): void
    {
        if (mb_strlen($artigo->getTituloSeo()) > self::MAX_TITULO_SEO) {
            $this->erro('tituloSeo', sprintf('O título SEO pode ter até %d caracteres.', self::MAX_TITULO_SEO));
        }
        if (mb_strlen($artigo->getDescricao()) > self::MAX_DESCRICAO) {
            $this->erro('descricao', sprintf('A meta description pode ter até %d caracteres.', self::MAX_DESCRICAO));
        }
    }

    private function exigirSlug(string $slug): void
    {
        if (preg_match(ArtigoDoBlog::REGEX_SLUG, $slug) !== 1) {
            $this->erro('slug', 'Slug em minúsculas, sem acento, separado por hífen.');
        }
    }

    private function exigirCatalogo(ArtigoDoBlog $artigo): void
    {
        if (!CatalogoDoBlog::existeCategoria($artigo->getCategoria())) {
            $this->erro('categoria', 'Escolha uma das categorias do blog.');
        }
        if (!CatalogoDoBlog::existeAutor($artigo->getAutor())) {
            $this->erro('autor', 'Escolha um dos autores do blog.');
        }
    }

    private function exigirRevisor(ArtigoDoBlog $artigo): void
    {
        if (($artigo->getRevisorNome() === null) !== ($artigo->getRevisorOab() === null)) {
            $this->erro('revisor', 'Informe o nome e a OAB do revisor, ou deixe os dois em branco.');
        }
    }

    private function exigirAltDaImagem(ArtigoDoBlog $artigo): void
    {
        if ($artigo->getImagemChave() !== null && $artigo->getImagemAlt() === null) {
            $this->erro('imagemAlt', 'A imagem de compartilhamento precisa do texto alternativo.');
        }
    }

    /** @param array<int, array<string, mixed>> $referencias o json pode vir de fora do formato */
    private function exigirReferencias(array $referencias): void
    {
        foreach (array_values($referencias) as $i => $referencia) {
            $numero = $i + 1;
            if (self::campo($referencia, 'texto') === '' || self::campo($referencia, 'rotulo') === '') {
                $this->erro('referencias', sprintf('A referência %d precisa de texto e rótulo.', $numero));
            }
            if (!self::urlValida(self::campo($referencia, 'url'))) {
                $this->erro('referencias', sprintf('A referência %d precisa de um endereço que comece com http:// ou https://.', $numero));
            }
        }
    }

    /** @param list<string> $slugsExistentes */
    private function exigirRelacionados(ArtigoDoBlog $artigo, array $slugsExistentes): void
    {
        foreach ($artigo->getRelacionados() as $relacionado) {
            if ($relacionado === $artigo->getSlug()) {
                $this->erro('relacionados', 'O artigo não pode ser relacionado a ele mesmo.');
                continue;
            }
            if (preg_match(ArtigoDoBlog::REGEX_SLUG, $relacionado) !== 1 || !\in_array($relacionado, $slugsExistentes, true)) {
                $this->erro('relacionados', sprintf('O artigo relacionado "%s" não existe.', $relacionado));
            }
        }
    }

    /** @param array<string, mixed> $referencia */
    private static function campo(array $referencia, string $nome): string
    {
        $valor = $referencia[$nome] ?? '';

        return \is_string($valor) ? trim($valor) : '';
    }

    private static function urlValida(string $url): bool
    {
        // Sem FILTER_VALIDATE_URL: ele recusa endereço com acento, que o z.url() do site aceita.
        if (preg_match('/\s/u', $url) === 1) {
            return false;
        }
        $partes = parse_url($url);
        if ($partes === false || ($partes['host'] ?? '') === '') {
            return false;
        }

        return \in_array(strtolower($partes['scheme'] ?? ''), ['http', 'https'], true);
    }

    private function erro(string $campo, string $mensagem): void
    {
        $this->erros[$campo][] = $mensagem;
    }
}
