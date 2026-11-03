<?php

declare(strict_types=1);

namespace App\Blog\Service;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Exception\ArtigoDoRepositorioRecusado;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Um `.md` de artigo do repositório dos sites, separado em frontmatter (YAML) e corpo (Markdown). Usado pela importação
 * (fatia P2): o comando lê o slug para juntar o mesmo artigo dos dois blogs; o UseCase lê o resto.
 */
final readonly class ArquivoDoArtigo
{
    /** @param array<string, mixed> $frontmatter */
    private function __construct(
        public array $frontmatter,
        public string $corpo,
    ) {
    }

    public static function ler(string $conteudo): self
    {
        $conteudo = str_replace("\r\n", "\n", $conteudo);
        if (str_starts_with($conteudo, "\u{FEFF}")) {
            $conteudo = substr($conteudo, 3);
        }
        if (preg_match('/^---\n(.*?)\n---[ \t]*(?:\n(.*))?$/s', $conteudo, $m) !== 1) {
            throw ArtigoDoRepositorioRecusado::semFrontmatter();
        }

        try {
            $dados = Yaml::parse($m[1], Yaml::PARSE_DATETIME);
        } catch (ParseException $e) {
            throw ArtigoDoRepositorioRecusado::frontmatterInvalido($e->getMessage());
        }
        if (!\is_array($dados)) {
            throw ArtigoDoRepositorioRecusado::frontmatterInvalido('o frontmatter precisa ser uma lista de campos.');
        }

        $frontmatter = [];
        foreach ($dados as $chave => $valor) {
            $frontmatter[(string) $chave] = $valor;
        }
        $arquivo = new self($frontmatter, $m[2] ?? '');
        $arquivo->slug(); // recusa já na leitura o arquivo sem slug válido

        return $arquivo;
    }

    public function slug(): string
    {
        $slug = $this->frontmatter['slug'] ?? null;
        $slug = \is_string($slug) ? $slug : '';
        if (\strlen($slug) > 120 || preg_match(ArtigoDoBlog::REGEX_SLUG, $slug) !== 1) {
            throw ArtigoDoRepositorioRecusado::slugInvalido($slug);
        }

        return $slug;
    }
}
