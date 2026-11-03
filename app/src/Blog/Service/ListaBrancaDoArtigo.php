<?php

declare(strict_types=1);

namespace App\Blog\Service;

use App\Blog\Entity\ArtigoDoBlog;

/**
 * Lista branca do corpo do artigo (spec painel-artigos-blogs, seção 3): monta uma árvore NOVA só com o que o blog
 * aceita. O que o TinyMCE deixa passar não é confiável só por vir do navegador.
 *
 * Regras (com a segurança do conversor pedida pelo Coordenador em 03/11):
 *  - ficam p, h2, h3, strong/b, em/i, a, ul, ol, li, blockquote, img, br e os blocos `div[data-bj]`;
 *    b vira strong e i vira em;
 *  - tag fora da lista some e deixa o texto; script e style somem inteiros, com o texto;
 *  - todo atributo cai, menos href (a), src e alt (img), data-bj e data-artigo (div);
 *  - href só http(s), mailto ou caminho relativo; javascript:, data:, vbscript: e outros esquemas derrubam o link
 *    e fica o texto. O esquema é lido sem diferenciar maiúsculas e ignorando espaços e controles;
 *  - src de img só `/master/blog/imagens/{id}`; qualquer outro derruba a imagem;
 *  - os blocos `data-bj` só valem no nível de cima do corpo (não se aninham).
 *
 * Uso interno do {@see HtmlDoArtigoParaMarkdown}.
 */
final class ListaBrancaDoArtigo
{
    public const REGEX_SRC_DA_IMAGEM = '#^/master/blog/imagens/([1-9][0-9]{0,9})$#';
    public const BLOCOS = ['aviso', 'etapas', 'checklist', 'cta-meio', 'leia-tambem'];

    private const TROCAS = ['b' => 'strong', 'i' => 'em'];
    private const PERMITIDOS = ['p', 'h2', 'h3', 'strong', 'em', 'a', 'ul', 'ol', 'li', 'blockquote', 'img', 'br'];
    private const SOMEM_COM_TEXTO = ['script', 'style'];
    /** Tags de bloco desconhecidas: o texto fica, com um espaço para não grudar palavras vizinhas. */
    private const BLOCOS_DESCONHECIDOS = ['div', 'section', 'article', 'header', 'footer', 'aside', 'nav', 'main', 'h1', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tr', 'td', 'th', 'pre', 'figure', 'figcaption', 'dl', 'dt', 'dd', 'address', 'hr'];
    private const ESQUEMAS_PERMITIDOS = ['http', 'https', 'mailto'];

    /** Devolve a raiz (um `<div>` de um documento novo) só com os nós da lista branca. */
    public function limpar(string $html): \DOMElement
    {
        $destino = new \DOMDocument('1.0', 'UTF-8');
        $raiz = $destino->createElement('div');
        $destino->appendChild($raiz);

        $origem = $this->carregar($html);
        if ($origem !== null) {
            $this->copiarFilhos($origem, $raiz, true);
        }

        return $raiz;
    }

    /** HTML dos filhos da raiz limpa. */
    public function paraHtml(\DOMElement $raiz): string
    {
        $saida = '';
        foreach ($raiz->childNodes as $filho) {
            $saida .= $raiz->ownerDocument?->saveHTML($filho);
        }

        return trim($saida);
    }

    /** Href sem esquema (caminho relativo) ou com http, https ou mailto; o esquema é lido sem diferenciar maiúsculas e sem espaços e controles. */
    public static function hrefPermitido(string $href): bool
    {
        // Entidade e barra invertida decodificadas antes de ler o esquema: "javascript&#58;" e "javascript\:" não passam por caminho relativo.
        $decodificado = str_replace('\\', '', html_entity_decode(html_entity_decode($href, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $compacto = (string) preg_replace('/[\x00-\x20\x7F]+/', '', $decodificado);
        if ($compacto === '') {
            return false;
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $compacto, $m) !== 1) {
            return true;
        }

        return \in_array(strtolower($m[1]), self::ESQUEMAS_PERMITIDOS, true);
    }

    private function carregar(string $html): ?\DOMElement
    {
        if (trim($html) === '') {
            return null;
        }

        $documento = new \DOMDocument('1.0', 'UTF-8');
        $antes = libxml_use_internal_errors(true);
        $documento->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($antes);

        $corpo = $documento->getElementsByTagName('body')->item(0);

        return $corpo instanceof \DOMElement ? $corpo : null;
    }

    private function copiarFilhos(\DOMNode $origem, \DOMElement $destino, bool $noTopo): void
    {
        foreach ($origem->childNodes as $filho) {
            if ($filho instanceof \DOMText && !$filho instanceof \DOMCdataSection) {
                $destino->appendChild($this->documento($destino)->createTextNode($filho->data));
            } elseif ($filho instanceof \DOMElement) {
                $this->copiarElemento($filho, $destino, $noTopo);
            }
            // comentário, CDATA e instrução de processamento caem
        }
    }

    private function copiarElemento(\DOMElement $elemento, \DOMElement $destino, bool $noTopo): void
    {
        $nome = strtolower($elemento->tagName);
        $nome = self::TROCAS[$nome] ?? $nome;
        if (\in_array($nome, self::SOMEM_COM_TEXTO, true)) {
            return;
        }
        if ($nome === 'div' && $noTopo && \in_array($elemento->getAttribute('data-bj'), self::BLOCOS, true)) {
            $this->copiarBloco($elemento, $destino);

            return;
        }
        if (!\in_array($nome, self::PERMITIDOS, true)) {
            $this->desembrulhar($elemento, $nome, $destino);

            return;
        }

        match ($nome) {
            'a' => $this->copiarLink($elemento, $destino),
            'img' => $this->copiarImagem($elemento, $destino),
            default => $this->copiarFilhos($elemento, $this->anexar($destino, $nome), false),
        };
    }

    private function desembrulhar(\DOMElement $elemento, string $nome, \DOMElement $destino): void
    {
        $this->copiarFilhos($elemento, $destino, false);
        if (\in_array($nome, self::BLOCOS_DESCONHECIDOS, true)) {
            $destino->appendChild($this->documento($destino)->createTextNode(' '));
        }
    }

    private function copiarLink(\DOMElement $link, \DOMElement $destino): void
    {
        $href = trim($link->getAttribute('href'));
        if (!self::hrefPermitido($href)) {
            $this->copiarFilhos($link, $destino, false);

            return;
        }

        $novo = $this->anexar($destino, 'a');
        $novo->setAttribute('href', $href);
        $this->copiarFilhos($link, $novo, false);
    }

    private function copiarImagem(\DOMElement $imagem, \DOMElement $destino): void
    {
        $src = trim($imagem->getAttribute('src'));
        if (preg_match(self::REGEX_SRC_DA_IMAGEM, $src) !== 1) {
            return;
        }

        $nova = $this->anexar($destino, 'img');
        $nova->setAttribute('src', $src);
        $nova->setAttribute('alt', trim($imagem->getAttribute('alt')));
    }

    private function copiarBloco(\DOMElement $bloco, \DOMElement $destino): void
    {
        $tipo = $bloco->getAttribute('data-bj');
        $artigo = trim($bloco->getAttribute('data-artigo'));
        if ($tipo === 'leia-tambem' && (\strlen($artigo) > 120 || preg_match(ArtigoDoBlog::REGEX_SLUG, $artigo) !== 1)) {
            return;
        }

        $novo = $this->anexar($destino, 'div');
        $novo->setAttribute('data-bj', $tipo);
        if ($tipo === 'leia-tambem') {
            $novo->setAttribute('data-artigo', $artigo);
        }
        if (\in_array($tipo, ['aviso', 'etapas', 'checklist'], true)) {
            $this->copiarFilhos($bloco, $novo, false);
        }
    }

    private function anexar(\DOMElement $destino, string $nome): \DOMElement
    {
        $novo = $this->documento($destino)->createElement($nome);
        $destino->appendChild($novo);

        return $novo;
    }

    private function documento(\DOMNode $no): \DOMDocument
    {
        $documento = $no->ownerDocument;
        if (!$documento instanceof \DOMDocument) {
            throw new \LogicException('Nó sem documento.');
        }

        return $documento;
    }
}
