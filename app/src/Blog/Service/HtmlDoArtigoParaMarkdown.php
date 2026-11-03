<?php

declare(strict_types=1);

namespace App\Blog\Service;

/**
 * Converte o HTML do editor no Markdown do blog (spec painel-artigos-blogs, seção 3), com as diretivas do repositório
 * dos sites (`blog/src/lib/diretivas.mjs`):
 *
 *   div[data-bj=aviso]        :::aviso ... :::
 *   div[data-bj=etapas] > ol  :::etapas + "1. **Título.** texto" + :::
 *   div[data-bj=checklist]    :::checklist + "- ..." + :::
 *   div[data-bj=cta-meio]     ::cta-meio
 *   div[data-bj=leia-tambem]  ::leia-tambem{artigo=slug}
 *   img /master/blog/imagens/{id}   ![alt](./imagens/{id}.{ext})
 *
 * Conversor próprio, sem dependência nova: DOMDocument e a {@see ListaBrancaDoArtigo}.
 *
 * Segurança (Coordenador, 03/11): o Astro repassa o HTML cru que estiver no Markdown, então a saída NUNCA tem HTML.
 * No texto, no alt e no texto do link, `<`, `>` e `&` saem como `&lt;`, `&gt;` e `&amp;`; os sinais do Markdown
 * (`\`, `*`, `_`, `` ` ``, `[`, `]`, `~`) saem com barra; `#`, `-`, `+`, `:`, `=` e "1." no começo da linha também.
 *
 * `/master/blog/imagens/{id}` só vale no editor: a imagem sai como `./imagens/{id}.{ext}`, com a extensão do mapa
 * `id => ext` (de {@see \App\Blog\Entity\ImagemDoArtigo::extensao()}). Imagem fora do mapa cai.
 *
 * Etapas ou checklist sem lista dentro perdem a moldura e ficam como texto comum (o build recusaria a diretiva vazia).
 */
final class HtmlDoArtigoParaMarkdown
{
    private const PREFIXO_DA_IMAGEM = './imagens/';

    /** @var array<int, string> */
    private array $extensoes = [];

    public function __construct(
        private readonly ListaBrancaDoArtigo $listaBranca = new ListaBrancaDoArtigo(),
    ) {
    }

    /** O HTML limpo pela lista branca: é o que o `corpoHtml` guarda. */
    public function sanitizar(string $html): string
    {
        return $this->listaBranca->paraHtml($this->listaBranca->limpar($html));
    }

    /** @param array<int, string> $extensoesDasImagens id da ImagemDoArtigo => extensão (jpg, png, webp) */
    public function converter(string $html, array $extensoesDasImagens = []): string
    {
        $this->extensoes = $extensoesDasImagens;
        $blocos = $this->blocos($this->listaBranca->limpar($html));

        return $blocos === [] ? '' : implode("\n\n", $blocos) . "\n";
    }

    // ---- blocos ----

    /** @return list<string> */
    private function blocos(\DOMNode $pai): array
    {
        $blocos = [];
        $soltos = [];
        foreach ($pai->childNodes as $filho) {
            if (!$this->ehBloco($filho)) {
                $soltos[] = $filho;
                continue;
            }
            $blocos[] = $this->paragrafo($soltos);
            $soltos = [];
            $blocos[] = $filho instanceof \DOMElement ? $this->bloco($filho) : '';
        }
        $blocos[] = $this->paragrafo($soltos);

        return array_values(array_filter($blocos, static fn (string $b): bool => $b !== ''));
    }

    private function ehBloco(\DOMNode $no): bool
    {
        return $no instanceof \DOMElement && \in_array($no->tagName, ['p', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'div'], true);
    }

    private function bloco(\DOMElement $el): string
    {
        return match ($el->tagName) {
            'p', 'li' => $this->paragrafo(iterator_to_array($el->childNodes)),
            'h2' => $this->titulo('## ', $el),
            'h3' => $this->titulo('### ', $el),
            'ul' => $this->lista($el, false),
            'ol' => $this->lista($el, true),
            'blockquote' => $this->citacao($el),
            default => $this->blocoBlueJus($el),
        };
    }

    /** @param array<\DOMNode> $nos */
    private function paragrafo(array $nos): string
    {
        $texto = $this->arrumarEspacos($this->inlineDe($nos));

        return $this->escaparInicioDasLinhas($texto);
    }

    private function titulo(string $marca, \DOMElement $el): string
    {
        $texto = $this->arrumarEspacos($this->inlineDe(iterator_to_array($el->childNodes)));

        return $texto === '' ? '' : $marca . str_replace("\\\n", ' ', $texto);
    }

    private function citacao(\DOMElement $el): string
    {
        $dentro = implode("\n\n", $this->blocos($el));
        if ($dentro === '') {
            return '';
        }

        $linhas = array_map(static fn (string $l): string => $l === '' ? '>' : '> ' . $l, explode("\n", $dentro));

        return implode("\n", $linhas);
    }

    private function blocoBlueJus(\DOMElement $el): string
    {
        $tipo = $el->getAttribute('data-bj');

        return match ($tipo) {
            'cta-meio' => '::cta-meio',
            'leia-tambem' => '::leia-tambem{artigo=' . $el->getAttribute('data-artigo') . '}',
            'etapas' => $this->blocoComLista('etapas', $el, true),
            'checklist' => $this->blocoComLista('checklist', $el, false),
            'aviso' => $this->moldura('aviso', $this->blocos($el)),
            default => implode("\n\n", $this->blocos($el)),
        };
    }

    /** Etapas é sempre lista numerada; checklist, sempre com traço. Sem lista dentro, fica só o texto. */
    private function blocoComLista(string $nome, \DOMElement $el, bool $numerada): string
    {
        $temLista = false;
        $blocos = [];
        foreach ($el->childNodes as $filho) {
            if ($filho instanceof \DOMElement && \in_array($filho->tagName, ['ul', 'ol'], true)) {
                $temLista = true;
                $blocos[] = $this->lista($filho, $numerada);
                continue;
            }
            $blocos[] = $this->paragrafo([$filho]);
        }
        $blocos = array_values(array_filter($blocos, static fn (string $b): bool => $b !== ''));

        return $temLista ? $this->moldura($nome, $blocos) : implode("\n\n", $blocos);
    }

    /** @param list<string> $blocos */
    private function moldura(string $nome, array $blocos): string
    {
        return $blocos === [] ? '' : ':::' . $nome . "\n" . implode("\n\n", $blocos) . "\n:::";
    }

    // ---- listas ----

    private function lista(\DOMElement $lista, bool $numerada, string $recuo = ''): string
    {
        $linhas = [];
        $n = 0;
        foreach ($lista->childNodes as $item) {
            if (!$item instanceof \DOMElement || $item->tagName !== 'li') {
                continue;
            }
            $marca = $numerada ? (++$n) . '. ' : '- ';
            $linhas[] = $this->itemDaLista($item, $recuo, $marca);
        }

        return implode("\n", array_filter($linhas, static fn (string $l): bool => $l !== ''));
    }

    private function itemDaLista(\DOMElement $item, string $recuo, string $marca): string
    {
        $inline = [];
        $subListas = [];
        foreach ($item->childNodes as $filho) {
            if ($filho instanceof \DOMElement && \in_array($filho->tagName, ['ul', 'ol'], true)) {
                $subListas[] = $this->lista($filho, $filho->tagName === 'ol', $recuo . str_repeat(' ', \strlen($marca)));
                continue;
            }
            $inline[] = $filho;
        }

        $texto = $this->escaparInicioDasLinhas($this->arrumarEspacos($this->inlineDe($inline)));
        $texto = str_replace("\n", "\n" . $recuo . str_repeat(' ', \strlen($marca)), $texto);
        $linha = $texto === '' ? '' : $recuo . $marca . $texto;

        return implode("\n", array_filter([$linha, ...$subListas], static fn (string $l): bool => $l !== ''));
    }

    // ---- texto corrido ----

    /** @param iterable<\DOMNode> $nos */
    private function inlineDe(iterable $nos): string
    {
        $saida = '';
        foreach ($nos as $no) {
            $saida .= $this->inline($no);
        }

        return $saida;
    }

    private function inline(\DOMNode $no): string
    {
        if ($no instanceof \DOMText) {
            return self::escaparTexto((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $no->data));
        }
        if (!$no instanceof \DOMElement) {
            return '';
        }

        return match ($no->tagName) {
            'strong' => $this->envolver('**', $this->inlineDe($no->childNodes)),
            'em' => $this->envolver('*', $this->inlineDe($no->childNodes)),
            'a' => $this->linkEmMarkdown($no),
            'img' => $this->imagem($no),
            'br' => "\\\n",
            // p, li ou título dentro de texto corrido (lista, citação): vira texto separado por espaço
            default => ' ' . $this->inlineDe($no->childNodes) . ' ',
        };
    }

    /** Espaço das pontas fica fora da marca: "** texto **" não é negrito no Markdown. */
    private function envolver(string $marca, string $dentro): string
    {
        $miolo = trim($dentro, ' ');
        if ($miolo === '') {
            return $dentro;
        }

        $antes = str_starts_with($dentro, ' ') ? ' ' : '';
        $depois = str_ends_with($dentro, ' ') ? ' ' : '';

        return $antes . $marca . $miolo . $marca . $depois;
    }

    private function linkEmMarkdown(\DOMElement $a): string
    {
        $texto = $this->inlineDe($a->childNodes);
        $href = $a->getAttribute('href');
        if (trim($texto) === '' || !ListaBrancaDoArtigo::hrefPermitido($href)) {
            return $texto;
        }

        return $this->envolverLink($texto, self::escaparUrl($href));
    }

    private function envolverLink(string $texto, string $url): string
    {
        $antes = str_starts_with($texto, ' ') ? ' ' : '';
        $depois = str_ends_with($texto, ' ') ? ' ' : '';

        return $antes . '[' . trim($texto, ' ') . '](' . $url . ')' . $depois;
    }

    private function imagem(\DOMElement $img): string
    {
        if (preg_match(ListaBrancaDoArtigo::REGEX_SRC_DA_IMAGEM, $img->getAttribute('src'), $m) !== 1) {
            return '';
        }
        $extensao = $this->extensoes[(int) $m[1]] ?? null;
        if ($extensao === null || preg_match('/^[a-z0-9]{2,5}$/', $extensao) !== 1) {
            return '';
        }

        $alt = self::escaparTexto((string) preg_replace('/\s+/u', ' ', trim($img->getAttribute('alt'))));

        return '![' . $alt . '](' . self::PREFIXO_DA_IMAGEM . $m[1] . '.' . $extensao . ')';
    }

    private function arrumarEspacos(string $texto): string
    {
        $texto = (string) preg_replace('/ {2,}/', ' ', $texto);
        $texto = (string) preg_replace('/ *\\\\\n */', "\\\n", $texto);
        $texto = trim($texto, " \n");
        while (str_ends_with($texto, '\\')) {
            $texto = rtrim(substr($texto, 0, -1), " \n");
        }

        return $texto;
    }

    // ---- escape ----

    public static function escaparTexto(string $texto): string
    {
        $texto = str_replace('\\', '\\\\', $texto);
        $texto = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $texto);

        return (string) preg_replace('/([*_`\[\]~])/', '\\\\$1', $texto);
    }

    /** Sinal que abriria título, lista, citação, linha ou diretiva no começo da linha sai escapado. */
    private function escaparInicioDasLinhas(string $texto): string
    {
        $texto = (string) preg_replace('/^([#+\-:=])/m', '\\\\$1', $texto);

        return (string) preg_replace('/^(\d+)([.)])(?=\s|$)/m', '$1\\\\$2', $texto);
    }

    private static function escaparUrl(string $href): string
    {
        $href = trim($href);

        return str_replace([' ', '(', ')', '<', '>', '"', "\n", "\r", "\t"], ['%20', '%28', '%29', '%3C', '%3E', '%22', '', '', ''], $href);
    }
}
