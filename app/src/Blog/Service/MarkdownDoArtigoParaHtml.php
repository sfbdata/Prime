<?php

declare(strict_types=1);

namespace App\Blog\Service;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Exception\ArtigoDoRepositorioRecusado;

/**
 * O caminho de volta do {@see HtmlDoArtigoParaMarkdown}: lê o Markdown de um artigo do repositório dos sites e devolve o
 * HTML que o editor guarda (spec painel-artigos-blogs, seção 3, tabela dos blocos). Usado só na importação (fatia P2).
 *
 * Só o subconjunto que o blog usa: parágrafo, `##`, `###`, negrito, itálico, link, listas (com e sem número, aninhadas),
 * citação, quebra de linha, tabela GFM e as diretivas do repositório dos sites:
 *
 *   :::aviso ... :::                  <div data-bj="aviso"><p>...</p></div>
 *   :::etapas + "1. **T.** texto"     <div data-bj="etapas"><ol><li><strong>T.</strong> texto</li></ol></div>
 *   :::checklist + "- ..."            <div data-bj="checklist"><ul><li>...</li></ul></div>
 *   ::cta-meio                        <div data-bj="cta-meio"></div>
 *   ::leia-tambem{artigo=slug}        <div data-bj="leia-tambem" data-artigo="slug"></div>
 *
 * Tabela GFM (`| a | b |` + `| --- | --- |` + linhas): sai na forma que a lista branca guarda,
 * `<table><thead><tr><th>a</th>...</tr></thead><tbody><tr><td>...</td></tr></tbody></table>` (sem tbody quando só há o
 * cabeçalho). O alinhamento (`:---:`) não é guardado; linha com menos células ganha células vazias e a com mais perde
 * as que sobram, como no GFM. `\|` é a barra dentro da célula.
 *
 * O que fica fora do subconjunto é RECUSADO com mensagem, para nada sumir calado na importação: imagem (as imagens
 * entram pela P3), bloco de código, título de nível 1, 4, 5 ou 6, linha horizontal, HTML no meio do texto e diretiva
 * desconhecida.
 *
 * Segurança: todo texto sai escapado (`<`, `>` e `&`); link só com o href que a {@see ListaBrancaDoArtigo} aceita
 * (`javascript:` e companhia caem e fica o texto). Mesmo assim, quem grava passa o resultado pelo sanitizador do
 * domínio ({@see HtmlDoArtigoParaMarkdown::sanitizar()}).
 */
final class MarkdownDoArtigoParaHtml
{
    private const DIRETIVAS_DE_BLOCO = ['aviso', 'etapas', 'checklist'];
    private const REGEX_ITEM = '/^( {0,3})([-*+]|(\d{1,9})[.)])( +|$)/';
    private const PONTUACAO_ESCAPAVEL = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    public function converter(string $markdown): string
    {
        $linhas = explode("\n", str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $markdown));

        return implode("\n", $this->blocos($linhas));
    }

    // ---- blocos ----

    /**
     * @param list<string> $linhas
     *
     * @return list<string>
     */
    private function blocos(array $linhas): array
    {
        $saida = [];
        $i = 0;
        $total = \count($linhas);
        while ($i < $total) {
            if (trim($linhas[$i]) === '') {
                ++$i;
                continue;
            }
            [$saida[], $i] = $this->bloco($linhas, $i);
        }

        return array_values(array_filter($saida, static fn (string $b): bool => $b !== ''));
    }

    /**
     * Um bloco que começa na linha `$i` (que tem texto).
     *
     * @param list<string> $linhas
     *
     * @return array{string, int} HTML do bloco, posição da linha seguinte
     */
    private function bloco(array $linhas, int $i): array
    {
        $linha = $linhas[$i];
        $limpa = trim($linha);

        if (preg_match('/^:::([a-z][a-z-]*)\s*$/', $limpa, $m) === 1) {
            return $this->diretivaDeBloco($m[1], $linhas, $i + 1);
        }
        if (str_starts_with($limpa, ':::')) {
            // `:::` sem abertura, ou diretiva com rótulo e atributos, que o blog não usa
            throw ArtigoDoRepositorioRecusado::diretivaDesconhecida($limpa);
        }
        if (str_starts_with($limpa, '::')) {
            return [$this->diretivaDeLinha($limpa), $i + 1];
        }
        $this->recusarBlocoForaDoSubconjunto($linha);
        if (preg_match('/^ {0,3}(#{2,3})\s+(.*?)(?:\s+#+)?\s*$/', $linha, $m) === 1) {
            $tag = 'h' . \strlen($m[1]);

            return ['<' . $tag . '>' . $this->inline($m[2]) . '</' . $tag . '>', $i + 1];
        }
        if (preg_match('/^ {0,3}>/', $linha) === 1) {
            return $this->citacao($linhas, $i);
        }
        if (preg_match(self::REGEX_ITEM, $linha) === 1) {
            return $this->lista($linhas, $i);
        }
        if ($this->comecaTabela($linhas, $i)) {
            return $this->tabela($linhas, $i);
        }

        return $this->paragrafo($linhas, $i);
    }

    private function recusarBlocoForaDoSubconjunto(string $linha): void
    {
        if (preg_match('/^ {0,3}(```|~~~)/', $linha) === 1) {
            throw ArtigoDoRepositorioRecusado::foraDoSubconjunto('bloco de código');
        }
        if (preg_match('/^ {0,3}(#|#{4,6})(\s|$)/', $linha) === 1) {
            throw ArtigoDoRepositorioRecusado::foraDoSubconjunto('título de nível 1, 4, 5 ou 6 (o blog usa só ## e ###)');
        }
        if (preg_match('/^ {0,3}([-*_])( *\1){2,} *$/', $linha) === 1) {
            throw ArtigoDoRepositorioRecusado::foraDoSubconjunto('linha horizontal');
        }
    }

    /**
     * @param list<string> $linhas
     *
     * @return array{string, int}
     */
    private function diretivaDeBloco(string $nome, array $linhas, int $inicio): array
    {
        if (!\in_array($nome, self::DIRETIVAS_DE_BLOCO, true)) {
            throw ArtigoDoRepositorioRecusado::diretivaDesconhecida(':::' . $nome);
        }

        $dentro = [];
        $i = $inicio;
        while ($i < \count($linhas) && trim($linhas[$i]) !== ':::') {
            $dentro[] = $linhas[$i];
            ++$i;
        }
        if ($i >= \count($linhas)) {
            throw ArtigoDoRepositorioRecusado::diretivaSemFechamento($nome);
        }

        return ['<div data-bj="' . $nome . '">' . implode('', $this->blocos($dentro)) . '</div>', $i + 1];
    }

    private function diretivaDeLinha(string $linha): string
    {
        if ($linha === '::cta-meio') {
            return '<div data-bj="cta-meio"></div>';
        }
        if (preg_match('/^::leia-tambem\{\s*artigo\s*=\s*"?([^"}]*?)"?\s*\}$/', $linha, $m) === 1) {
            if (\strlen($m[1]) > 120 || preg_match(ArtigoDoBlog::REGEX_SLUG, $m[1]) !== 1) {
                throw ArtigoDoRepositorioRecusado::leiaTambemInvalido($m[1]);
            }

            return '<div data-bj="leia-tambem" data-artigo="' . $m[1] . '"></div>';
        }

        throw ArtigoDoRepositorioRecusado::diretivaDesconhecida($linha);
    }

    /**
     * @param list<string> $linhas
     *
     * @return array{string, int}
     */
    private function citacao(array $linhas, int $i): array
    {
        $dentro = [];
        while ($i < \count($linhas) && preg_match('/^ {0,3}> ?(.*)$/', $linhas[$i], $m) === 1) {
            $dentro[] = $m[1];
            ++$i;
        }
        $html = implode('', $this->blocos($dentro));

        return [$html === '' ? '' : '<blockquote>' . $html . '</blockquote>', $i];
    }

    /**
     * Parágrafo: as linhas seguidas até uma linha em branco ou o começo de outro bloco.
     *
     * @param list<string> $linhas
     *
     * @return array{string, int}
     */
    private function paragrafo(array $linhas, int $i): array
    {
        $texto = [];
        do {
            $texto[] = $linhas[$i];
            ++$i;
        } while ($i < \count($linhas) && !$this->fechaParagrafo($linhas[$i]));

        return ['<p>' . $this->inline(implode("\n", $texto)) . '</p>', $i];
    }

    private function fechaParagrafo(string $linha): bool
    {
        return trim($linha) === ''
            || preg_match('/^ {0,3}(#{1,6}(\s|$)|>|```|~~~)/', $linha) === 1
            || preg_match('/^\s*::/', $linha) === 1
            || preg_match('/^ {0,3}([-*+]|1[.)]) +\S/', $linha) === 1;
    }

    // ---- tabela ----

    /**
     * Linha com `|` seguida da linha de separação (`| --- | :---: |`) com o mesmo número de células.
     *
     * @param list<string> $linhas
     */
    private function comecaTabela(array $linhas, int $i): bool
    {
        $separacao = $linhas[$i + 1] ?? '';
        if (!str_contains($linhas[$i], '|') || !str_contains($separacao, '|')) {
            return false;
        }
        $marcas = self::celulasDaLinha($separacao);
        if ($marcas === []) {
            return false;
        }
        foreach ($marcas as $marca) {
            if (preg_match('/^:?-+:?$/', $marca) !== 1) {
                return false;
            }
        }

        return \count($marcas) === \count(self::celulasDaLinha($linhas[$i]));
    }

    /**
     * A tabela até a linha em branco ou o começo de outro bloco.
     *
     * @param list<string> $linhas
     *
     * @return array{string, int}
     */
    private function tabela(array $linhas, int $i): array
    {
        $cabecalho = self::celulasDaLinha($linhas[$i]);
        $colunas = \count($cabecalho);
        $html = '<table><thead>' . $this->linhaDaTabela($cabecalho, 'th') . '</thead>';
        $i += 2;

        $corpo = '';
        $total = \count($linhas);
        while ($i < $total && !$this->fechaParagrafo($linhas[$i])) {
            $celulas = \array_slice(array_pad(self::celulasDaLinha($linhas[$i]), $colunas, ''), 0, $colunas);
            $corpo .= $this->linhaDaTabela($celulas, 'td');
            ++$i;
        }

        return [$html . ($corpo === '' ? '' : '<tbody>' . $corpo . '</tbody>') . '</table>', $i];
    }

    /** @param list<string> $celulas */
    private function linhaDaTabela(array $celulas, string $tag): string
    {
        $html = '';
        foreach ($celulas as $celula) {
            $html .= '<' . $tag . '>' . $this->inline($celula) . '</' . $tag . '>';
        }

        return '<tr>' . $html . '</tr>';
    }

    /**
     * As células de uma linha da tabela, sem os espaços das pontas. A `|` das pontas é opcional; `\|` é a barra no
     * texto da célula (vira `|`), e as outras barras invertidas ficam para o texto corrido tratar.
     *
     * @return list<string>
     */
    private static function celulasDaLinha(string $linha): array
    {
        $linha = trim($linha);
        $celulas = [];
        $atual = '';
        $terminouNaBarra = false;
        for ($i = 0, $tamanho = \strlen($linha); $i < $tamanho; ++$i) {
            $c = $linha[$i];
            $terminouNaBarra = false;
            if ($c === '\\' && $i + 1 < $tamanho) {
                $atual .= $linha[$i + 1] === '|' ? '|' : $c . $linha[$i + 1];
                ++$i;
                continue;
            }
            if ($c === '|') {
                $celulas[] = trim($atual);
                $atual = '';
                $terminouNaBarra = true;
                continue;
            }
            $atual .= $c;
        }
        if (!$terminouNaBarra) {
            $celulas[] = trim($atual);
        }
        if (str_starts_with($linha, '|')) {
            array_shift($celulas);
        }

        return $celulas;
    }

    // ---- listas ----

    /**
     * Uma lista: os itens do mesmo tipo (com ou sem número) seguidos. O que vem recuado abaixo do item é dele
     * (continuação do texto ou lista de dentro).
     *
     * @param list<string> $linhas
     *
     * @return array{string, int}
     */
    private function lista(array $linhas, int $i): array
    {
        preg_match(self::REGEX_ITEM, $linhas[$i], $m);
        $numerada = $m[3] !== '';
        $itens = [];
        $total = \count($linhas);

        while ($i < $total && preg_match(self::REGEX_ITEM, $linhas[$i], $m) === 1 && ($m[3] !== '') === $numerada) {
            [$conteudo, $i] = $this->conteudoDoItem($linhas, $i, $m[0]);
            $itens[] = '<li>' . $this->itemDaLista($conteudo) . '</li>';
            // linhas em branco entre itens da mesma lista
            $proxima = $this->proximaComTexto($linhas, $i);
            if ($proxima > $i && $proxima < $total && preg_match(self::REGEX_ITEM, $linhas[$proxima], $p) === 1 && ($p[3] !== '') === $numerada) {
                $i = $proxima;
            }
        }

        $tag = $numerada ? 'ol' : 'ul';

        return ['<' . $tag . '>' . implode('', $itens) . '</' . $tag . '>', $i];
    }

    /**
     * As linhas de um item, a partir da linha da marca: o texto depois da marca e o que vem recuado abaixo.
     *
     * @param list<string> $linhas
     *
     * @return array{list<string>, int} conteúdo do item sem o recuo, posição da linha seguinte
     */
    private function conteudoDoItem(array $linhas, int $i, string $marca): array
    {
        $recuo = \strlen($marca) === \strlen(rtrim($marca)) ? \strlen($marca) + 1 : \strlen($marca);
        $conteudo = [substr($linhas[$i], \strlen($marca))];
        ++$i;
        $total = \count($linhas);
        while ($i < $total) {
            $linha = $linhas[$i];
            if (trim($linha) === '') {
                // linha em branco: o item continua só se a próxima linha com texto estiver recuada
                $proxima = $this->proximaComTexto($linhas, $i + 1);
                if ($proxima < $total && self::recuo($linhas[$proxima]) >= $recuo) {
                    $conteudo[] = '';
                    ++$i;
                    continue;
                }
                break;
            }
            if (self::recuo($linha) >= $recuo) {
                $conteudo[] = substr($linha, $recuo);
                ++$i;
                continue;
            }
            if (preg_match(self::REGEX_ITEM, $linha) === 1 || $this->fechaParagrafo($linha)) {
                break;
            }
            $conteudo[] = $linha; // continuação preguiçosa do texto do item
            ++$i;
        }

        return [$conteudo, $i];
    }

    /**
     * Posição da primeira linha com texto a partir de `$i` (o total de linhas, se não houver).
     *
     * @param list<string> $linhas
     */
    private function proximaComTexto(array $linhas, int $i): int
    {
        $total = \count($linhas);
        while ($i < $total && trim($linhas[$i]) === '') {
            ++$i;
        }

        return $i;
    }

    /**
     * Texto do item como inline (lista compacta, sem `<p>`); listas de dentro como blocos.
     *
     * @param list<string> $conteudo
     */
    private function itemDaLista(array $conteudo): string
    {
        $texto = [];
        $i = 0;
        while ($i < \count($conteudo) && preg_match(self::REGEX_ITEM, $conteudo[$i]) !== 1) {
            $texto[] = $conteudo[$i];
            ++$i;
        }
        $inline = $this->inline(trim(implode("\n", $texto)));
        $resto = implode('', $this->blocos(\array_slice($conteudo, $i)));

        return $inline . $resto;
    }

    private static function recuo(string $linha): int
    {
        return \strlen($linha) - \strlen(ltrim($linha, ' '));
    }

    // ---- texto corrido ----

    private function inline(string $texto): string
    {
        $saida = '';
        $buffer = '';
        $tamanho = \strlen($texto);
        $i = 0;

        while ($i < $tamanho) {
            $fim = $this->escapeOuQuebra($texto, $i, $saida, $buffer)
                ?? $this->imagemEntidadeOuHtml($texto, $i, $saida, $buffer)
                ?? $this->codigoLinkOuEnfase($texto, $i, $saida, $buffer);
            if ($fim !== null) {
                $i = $fim;
                continue;
            }

            $buffer .= $texto[$i];
            ++$i;
        }

        return $saida . $this->texto($buffer);
    }

    /**
     * Barra invertida (quebra de linha ou pontuação escapada) e quebra de linha do texto, em `$i`. O texto comum vai
     * para `$buffer`; o HTML pronto, para `$saida`.
     *
     * @return int|null posição depois do que foi lido; null se em `$i` não há nada disso
     */
    private function escapeOuQuebra(string $texto, int $i, string &$saida, string &$buffer): ?int
    {
        $c = $texto[$i];
        $tamanho = \strlen($texto);

        if ($c === '\\' && $i + 1 < $tamanho) {
            $proximo = $texto[$i + 1];
            if ($proximo === "\n") {
                $saida .= $this->texto($buffer) . '<br>';
                $buffer = '';

                return $i + 2;
            }
            if (str_contains(self::PONTUACAO_ESCAPAVEL, $proximo)) {
                $buffer .= $proximo;

                return $i + 2;
            }
        }
        if ($c !== "\n") {
            return null;
        }
        if (str_ends_with($buffer, '  ')) {
            $saida .= $this->texto(rtrim($buffer, ' ')) . '<br>';
            $buffer = '';
        } else {
            $buffer = rtrim($buffer, ' ') . ' ';
        }
        ++$i;
        while ($i < $tamanho && $texto[$i] === ' ') {
            ++$i;
        }

        return $i;
    }

    /**
     * Imagem (recusada), entidade HTML, link entre `<>` e HTML no meio do texto (recusado), em `$i`.
     *
     * @return int|null posição depois do que foi lido; null se em `$i` não há nada disso
     */
    private function imagemEntidadeOuHtml(string $texto, int $i, string &$saida, string &$buffer): ?int
    {
        $c = $texto[$i];
        if ($c === '!' && ($texto[$i + 1] ?? '') === '[') {
            throw ArtigoDoRepositorioRecusado::imagemNoCorpo();
        }
        if ($c === '&' && preg_match('/\G&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z][a-zA-Z0-9]{1,31});/', $texto, $m, 0, $i) === 1) {
            $decodificado = html_entity_decode($m[0], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            $buffer .= $decodificado === $m[0] ? $m[0] : $decodificado;

            return $i + \strlen($m[0]);
        }
        if ($c !== '<') {
            return null;
        }
        if (preg_match('/\G<((?:https?:|mailto:)[^\s<>]*)>/i', $texto, $m, 0, $i) === 1) {
            $saida .= $this->texto($buffer) . $this->linkHtml($this->texto($m[1]), $m[1]);
            $buffer = '';

            return $i + \strlen($m[0]);
        }
        if (preg_match('/\G<(\/?[a-zA-Z][a-zA-Z0-9-]*[\s\/>]|!--)/', $texto, $m, 0, $i) === 1) {
            throw ArtigoDoRepositorioRecusado::foraDoSubconjunto('HTML no meio do texto');
        }

        return null;
    }

    /**
     * Código entre crases, `[link](destino)` e ênfase (`*`, `_`), em `$i`.
     *
     * @return int|null posição depois do que foi lido; null se em `$i` não há nada disso
     */
    private function codigoLinkOuEnfase(string $texto, int $i, string &$saida, string &$buffer): ?int
    {
        $c = $texto[$i];
        if ($c === '`' && preg_match('/\G(`+)(.+?)\1(?!`)/s', $texto, $m, 0, $i) === 1) {
            // código no meio da frase: o blog não tem estilo para ele; fica o texto, sem a marca
            $buffer .= trim($m[2]);

            return $i + \strlen($m[0]);
        }
        if ($c === '[' && ($link = $this->lerLink($texto, $i)) !== null) {
            [$rotulo, $href, $fim] = $link;
            $saida .= $this->texto($buffer) . $this->linkHtml($this->inline($rotulo), $href);
            $buffer = '';

            return $fim;
        }
        if (($c === '*' || $c === '_') && ($enfase = $this->lerEnfase($texto, $i)) !== null) {
            [$tag, $dentro, $fim] = $enfase;
            $saida .= $this->texto($buffer) . '<' . $tag . '>' . $this->inline($dentro) . '</' . $tag . '>';
            $buffer = '';

            return $fim;
        }

        return null;
    }

    /**
     * `[rótulo](destino)` a partir de `$inicio`.
     *
     * @return array{string, string, int}|null rótulo em Markdown, href já sem escapes, posição depois do `)`
     */
    private function lerLink(string $texto, int $inicio): ?array
    {
        $fechaRotulo = $this->fechamento($texto, $inicio, '[', ']');
        if ($fechaRotulo === null || ($texto[$fechaRotulo + 1] ?? '') !== '(') {
            return null;
        }
        $fechaDestino = $this->fechamento($texto, $fechaRotulo + 1, '(', ')');
        if ($fechaDestino === null) {
            return null;
        }

        $destino = trim(substr($texto, $fechaRotulo + 2, $fechaDestino - $fechaRotulo - 2));
        // título opcional: [x](url "título") ou [x](<url> "título"); o título não é guardado
        if (preg_match('/^<([^<>\n]*)>/', $destino, $m) === 1) {
            $destino = $m[1];
        } else {
            $destino = (string) preg_replace('/\s+("[^"]*"|\'[^\']*\'|\([^)]*\))$/', '', $destino);
        }
        $destino = (string) preg_replace('/\\\\([' . preg_quote(self::PONTUACAO_ESCAPAVEL, '/') . '])/', '$1', $destino);
        $destino = html_entity_decode($destino, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return [substr($texto, $inicio + 1, $fechaRotulo - $inicio - 1), $destino, $fechaDestino + 1];
    }

    /** Posição do sinal que fecha o que abre em `$inicio`, contando os aninhados e pulando os escapados. */
    private function fechamento(string $texto, int $inicio, string $abre, string $fecha): ?int
    {
        $nivel = 0;
        for ($i = $inicio, $tamanho = \strlen($texto); $i < $tamanho; ++$i) {
            $c = $texto[$i];
            if ($c === '\\') {
                ++$i;
                continue;
            }
            if ($c === $abre) {
                ++$nivel;
            } elseif ($c === $fecha && --$nivel === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * `**negrito**`, `__negrito__`, `*itálico*` ou `_itálico_` a partir de `$inicio`. A marca que abre precisa de texto
     * logo depois e a que fecha, de texto logo antes; `_` no meio da palavra é só texto.
     *
     * @return array{string, string, int}|null tag, conteúdo em Markdown, posição depois da marca que fecha
     */
    private function lerEnfase(string $texto, int $inicio): ?array
    {
        $c = $texto[$inicio];
        $dupla = ($texto[$inicio + 1] ?? '') === $c;
        $marca = $dupla ? $c . $c : $c;
        $depois = $texto[$inicio + \strlen($marca)] ?? '';
        if ($depois === '' || ctype_space($depois)) {
            return null;
        }
        if ($c === '_' && $inicio > 0 && preg_match('/[\p{L}\p{N}]$/u', substr($texto, 0, $inicio)) === 1) {
            return null;
        }

        $tamanho = \strlen($texto);
        for ($i = $inicio + \strlen($marca); $i < $tamanho; ++$i) {
            if ($texto[$i] === '\\') {
                ++$i;
                continue;
            }
            if ($texto[$i] === '[' && ($link = $this->fechamento($texto, $i, '[', ']')) !== null) {
                $i = $link;
                continue;
            }
            if (substr($texto, $i, \strlen($marca)) !== $marca || ctype_space($texto[$i - 1])) {
                continue;
            }
            // `*` simples não fecha no começo de um `**`
            if (!$dupla && ($texto[$i + 1] ?? '') === $c) {
                ++$i;
                continue;
            }
            if ($c === '_' && preg_match('/^[\p{L}\p{N}]/u', substr($texto, $i + 1)) === 1) {
                continue;
            }

            return [$dupla ? 'strong' : 'em', substr($texto, $inicio + \strlen($marca), $i - $inicio - \strlen($marca)), $i + \strlen($marca)];
        }

        return null;
    }

    /** Link com href da lista branca; fora dela, fica só o texto. */
    private function linkHtml(string $rotuloHtml, string $href): string
    {
        $href = trim($href);
        if ($href === '' || !ListaBrancaDoArtigo::hrefPermitido($href)) {
            return $rotuloHtml;
        }

        return '<a href="' . htmlspecialchars($href, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">' . $rotuloHtml . '</a>';
    }

    private function texto(string $texto): string
    {
        return htmlspecialchars($texto, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
