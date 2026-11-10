<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;

/**
 * Acha a última página do PDF para o carimbo visível (etapa 5, bloco 5B), no lugar do `getPage(numPages)` do pdf.js
 * que o protótipo usa (bj-assina-pdf.js:23). Desce do catálogo pela árvore /Pages, sempre pelo último filho de /Kids
 * (direto ou em objeto à parte) que não seja um nó vazio (/Count 0), até uma folha. Como no pdf.js, é folha o
 * objeto com /Type /Page ou sem /Kids. Lê a área visível do `page.view` do pdf.js (o /CropBox recortado pelo
 * /MediaBox, cada um da página ou herdado do nó mais próximo que tenha um; sem /MediaBox válido, o tamanho carta) e o
 * /Annots: vetor direto ou referência a um vetor.
 *
 * Os objetos vêm do `CarimboVisivelObjetos`, com a regra de versão do leitor do motor (um objeto solto antigo vence a
 * cópia mais nova em /ObjStm, como no protótipo) e com o orçamento da procura inteira: cada objeto é lido e analisado
 * uma vez, e o /Kids tem teto de filhos. Só o primeiro nível de cada dicionário é lido. Sem estado e sem disco. Nunca
 * lança: árvore quebrada, ciclo ou orçamento estourado devolvem null.
 */
final class LocalizadorDaUltimaPagina
{
    /** Tamanho carta: a caixa do pdf.js quando não há /MediaBox válido. */
    public const CAIXA_CARTA = [0.0, 0.0, 612.0, 792.0];
    /** Referências num /Kids (o teto de objetos de um /ObjStm do motor). */
    public const FILHOS_MAXIMOS = LimitesDaConferencia::OBJETOS_MAXIMOS;

    private const ESPACOS = " \t\r\n\f\0";
    private const DELIMITADORES = " \t\r\n\f\0()<>[]{}/%";
    /** O que muda a leitura dentro de um vetor ou dicionário: o resto é pulado de uma vez. */
    private const ESPECIAIS_DO_GRUPO = '()<>[]%';
    private const NUMERO = '([+-]?(?:\d+\.?\d*|\.\d+))';

    public function localizar(string $pdf, int $numeroDaRaiz, int $geracaoDaRaiz): ?CarimboVisivelPagina
    {
        try {
            return self::procurar(new CarimboVisivelObjetos($pdf), [$numeroDaRaiz, $geracaoDaRaiz]);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array{int, int} $raiz */
    private static function procurar(CarimboVisivelObjetos $objetos, array $raiz): ?CarimboVisivelPagina
    {
        $catalogo = self::dicionario($objetos, $raiz);
        $no = $catalogo === null ? null : self::referencia($catalogo['/Pages'][0] ?? '');
        $ancestrais = [];
        $vistos = [];
        while ($no !== null) {
            $entradas = self::dicionario($objetos, $no);
            if ($entradas === null || isset($vistos[$no[0]])) {
                return null;
            }
            $vistos[$no[0]] = true;
            if (!self::ehNoDePaginas($entradas)) {
                return self::pagina($objetos, $no, $entradas, $ancestrais);
            }
            $ancestrais[] = $entradas;
            $no = self::ultimoFilho($objetos, $entradas);
        }

        return null;
    }

    /**
     * As entradas do dicionário do objeto, lido e analisado uma vez na procura. Null se não há ou não é dicionário.
     *
     * @param array{int, int} $referencia
     *
     * @return array<string, array{string, int, int}>|null
     */
    private static function dicionario(CarimboVisivelObjetos $objetos, array $referencia): ?array
    {
        return $objetos->analisado($referencia[0], $referencia[1], static fn (string $corpo): ?array => self::entradas($corpo));
    }

    /**
     * O último filho que não é nó vazio; null se não há nenhum ou se um deles não dá para ler.
     *
     * @param array<string, array{string, int, int}> $entradas
     *
     * @return array{int, int}|null
     */
    private static function ultimoFilho(CarimboVisivelObjetos $objetos, array $entradas): ?array
    {
        $filhos = self::filhos($objetos, $entradas['/Kids'][0] ?? '');
        for ($i = \count($filhos) - 1; $i >= 0; --$i) {
            $filho = self::dicionario($objetos, $filhos[$i]);
            if ($filho === null) {
                return null;
            }
            if (!self::ehNoVazio($filho)) {
                return $filhos[$i];
            }
        }

        return null;
    }

    /**
     * As referências do /Kids, vetor direto ou referência a um vetor, até `FILHOS_MAXIMOS`; acima disso, lança.
     *
     * @return list<array{int, int}>
     */
    private static function filhos(CarimboVisivelObjetos $objetos, string $valor): array
    {
        $referencia = self::referencia($valor);
        $vetor = $referencia === null ? $valor : (string) $objetos->objeto($referencia[0], $referencia[1]);
        if (($vetor[strspn($vetor, self::ESPACOS)] ?? '') !== '[') {
            return [];
        }
        if (substr_count($vetor, 'R') > self::FILHOS_MAXIMOS) {
            throw new \OverflowException('Filhos demais num /Kids.');
        }
        $objetos->gastar(\strlen($vetor));
        preg_match_all('/(?<![\d.])(\d{1,10})\s+(\d{1,5})\s+R(?![^\s\/\[\]<>(){}%])/', $vetor, $partes, \PREG_SET_ORDER);

        return array_map(static fn (array $parte): array => [(int) $parte[1], (int) $parte[2]], $partes);
    }

    /** @param array<string, array{string, int, int}> $entradas */
    private static function ehNoDePaginas(array $entradas): bool
    {
        return trim($entradas['/Type'][0] ?? '') !== '/Page' && isset($entradas['/Kids']);
    }

    /** @param array<string, array{string, int, int}> $entradas */
    private static function ehNoVazio(array $entradas): bool
    {
        return self::ehNoDePaginas($entradas) && trim($entradas['/Count'][0] ?? '') === '0';
    }

    /**
     * @param array{int, int}                              $referencia
     * @param array<string, array{string, int, int}>       $entradas
     * @param list<array<string, array{string, int, int}>> $ancestrais do mais alto ao mais próximo
     */
    private static function pagina(CarimboVisivelObjetos $objetos, array $referencia, array $entradas, array $ancestrais): CarimboVisivelPagina
    {
        $corpo = (string) $objetos->objeto($referencia[0], $referencia[1]);
        $anotacoes = $entradas['/Annots'] ?? null;
        $valor = $anotacoes === null ? null : $anotacoes[0];
        $referenciaDasAnotacoes = $valor === null ? null : self::referencia($valor);

        return new CarimboVisivelPagina(
            numero: $referencia[0],
            geracao: $referencia[1],
            corpo: $corpo,
            caixa: self::caixa($objetos, [$entradas, ...array_reverse($ancestrais)]),
            trechoDasAnotacoes: $anotacoes === null ? null : [$anotacoes[1], $anotacoes[2]],
            anotacoesDiretas: $valor !== null && str_starts_with($valor, '[') ? substr($valor, 1, -1) : null,
            referenciaDasAnotacoes: $referenciaDasAnotacoes,
            vetorDasAnotacoes: $referenciaDasAnotacoes === null ? null : $objetos->objeto($referenciaDasAnotacoes[0], $referenciaDasAnotacoes[1]),
            aberturaDoDicionario: self::depoisDosEspacos($corpo, 0),
        );
    }

    /**
     * A área visível, como o `page.view` do pdf.js: o /CropBox recortado pelo /MediaBox; sem /CropBox válido, ou sem
     * área em comum com o /MediaBox, o /MediaBox. Sem /MediaBox válido, o tamanho carta.
     *
     * @param list<array<string, array{string, int, int}>> $dicionarios da página para cima
     *
     * @return array{float, float, float, float}
     */
    private static function caixa(CarimboVisivelObjetos $objetos, array $dicionarios): array
    {
        $midia = self::caixaHerdada($objetos, $dicionarios, '/MediaBox') ?? self::CAIXA_CARTA;
        $corte = self::caixaHerdada($objetos, $dicionarios, '/CropBox');
        if ($corte === null) {
            return $midia;
        }
        $comum = [max($corte[0], $midia[0]), max($corte[1], $midia[1]), min($corte[2], $midia[2]), min($corte[3], $midia[3])];

        return $comum[2] - $comum[0] > 0 && $comum[3] - $comum[1] > 0 ? $comum : $midia;
    }

    /**
     * A caixa do dicionário mais próximo que tem a chave, como o `_getInheritableProperty` do pdf.js: inválida ou sem
     * área vira null, sem procurar mais acima.
     *
     * @param list<array<string, array{string, int, int}>> $dicionarios da página para cima
     *
     * @return array{float, float, float, float}|null
     */
    private static function caixaHerdada(CarimboVisivelObjetos $objetos, array $dicionarios, string $chave): ?array
    {
        foreach ($dicionarios as $entradas) {
            if (isset($entradas[$chave])) {
                return self::retangulo($objetos, $entradas[$chave][0]);
            }
        }

        return null;
    }

    /**
     * Os quatro números normalizados (cantos em qualquer ordem, como o `normalizeRect` do pdf.js); null sem área.
     *
     * @return array{float, float, float, float}|null
     */
    private static function retangulo(CarimboVisivelObjetos $objetos, string $valor): ?array
    {
        $referencia = self::referencia($valor);
        $vetor = trim($referencia === null ? $valor : (string) $objetos->objeto($referencia[0], $referencia[1]));
        $n = self::NUMERO;
        if (preg_match('/^\[\s*' . $n . '\s+' . $n . '\s+' . $n . '\s+' . $n . '\s*\]$/', $vetor, $p) !== 1) {
            return null;
        }
        [$xa, $ya, $xb, $yb] = [(float) $p[1], (float) $p[2], (float) $p[3], (float) $p[4]];
        $caixa = [min($xa, $xb), min($ya, $yb), max($xa, $xb), max($ya, $yb)];

        return $caixa[2] - $caixa[0] > 0 && $caixa[3] - $caixa[1] > 0 ? $caixa : null;
    }

    /** @return array{int, int}|null */
    private static function referencia(string $valor): ?array
    {
        return preg_match('/^\s*(\d{1,10})\s+(\d{1,5})\s+R\s*$/', $valor, $p) === 1 ? [(int) $p[1], (int) $p[2]] : null;
    }

    /**
     * O primeiro nível de um dicionário: nome => [valor como está no texto, início do nome, fim do valor]. Nome
     * repetido fica com o último valor, como no pdf.js. Null se o texto não começa com um dicionário completo.
     *
     * @return array<string, array{string, int, int}>|null
     */
    private static function entradas(string $texto): ?array
    {
        $i = self::depoisDosEspacos($texto, 0);
        if (substr($texto, $i, 2) !== '<<') {
            return null;
        }
        $entradas = [];
        $i = self::depoisDosEspacos($texto, $i + 2);
        while (($texto[$i] ?? '') === '/') {
            $fimDoNome = $i + 1 + strcspn($texto, self::DELIMITADORES, $i + 1);
            $inicioDoValor = self::depoisDosEspacos($texto, $fimDoNome);
            $fim = self::fimDoValor($texto, $inicioDoValor);
            if ($fim === null) {
                return null;
            }
            $entradas[substr($texto, $i, $fimDoNome - $i)] = [substr($texto, $inicioDoValor, $fim - $inicioDoValor), $i, $fim];
            $i = self::depoisDosEspacos($texto, $fim);
        }

        return substr($texto, $i, 2) === '>>' ? $entradas : null;
    }

    /** Pula espaços e comentários. */
    private static function depoisDosEspacos(string $texto, int $i): int
    {
        while (true) {
            $i += strspn($texto, self::ESPACOS, $i);
            if (($texto[$i] ?? '') !== '%') {
                return $i;
            }
            $i += strcspn($texto, "\r\n", $i);
        }
    }

    /** Onde acaba o valor que começa em `$i`; null se ali não começa um valor. */
    private static function fimDoValor(string $texto, int $i): ?int
    {
        $c = $texto[$i] ?? '';

        return match (true) {
            $c === '' || str_contains(')>]{}', $c) => null,
            $c === '(' => self::fimDoTexto($texto, $i),
            $c === '<' && ($texto[$i + 1] ?? '') !== '<' => self::fimDoHexadecimal($texto, $i),
            $c === '<' || $c === '[' => self::fimDoGrupo($texto, $i),
            $c === '/' => $i + 1 + strcspn($texto, self::DELIMITADORES, $i + 1),
            default => self::fimDaPalavra($texto, $i),
        };
    }

    /** Número, palavra (`true`, `null`) ou referência `n g R`. */
    private static function fimDaPalavra(string $texto, int $i): int
    {
        $fim = $i + strcspn($texto, self::DELIMITADORES, $i);
        if (ctype_digit(substr($texto, $i, $fim - $i))
            && preg_match('/\G\s+\d+\s+R(?![^\s\/\[\]<>(){}%])/', $texto, $p, 0, $fim) === 1) {
            return $fim + \strlen($p[0]);
        }

        return $fim;
    }

    /** Vetor ou dicionário, com o que houver dentro (textos, hexadecimais, comentários, outros grupos). */
    private static function fimDoGrupo(string $texto, int $i): ?int
    {
        $profundidade = 0;
        $tamanho = \strlen($texto);
        while ($i < $tamanho) {
            $passo = self::passoNoGrupo($texto, $i);
            if ($passo === null) {
                return null;
            }
            [$i, $mudanca] = $passo;
            $profundidade += $mudanca;
            if ($profundidade === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * O próximo pedaço dentro de um grupo: a posição depois dele e quanto a profundidade muda.
     *
     * @return array{int, int}|null
     */
    private static function passoNoGrupo(string $texto, int $i): ?array
    {
        $dois = substr($texto, $i, 2);
        $c = $texto[$i];
        $fim = match (true) {
            $dois === '<<' || $dois === '>>' => $i + 2,
            $c === '[' || $c === ']' => $i + 1,
            $c === '%' => $i + strcspn($texto, "\r\n", $i),
            $c === '(' => self::fimDoTexto($texto, $i),
            $c === '<' => self::fimDoHexadecimal($texto, $i),
            default => $i + 1 + strcspn($texto, self::ESPECIAIS_DO_GRUPO, $i + 1),
        };
        $mudanca = match (true) {
            $dois === '<<' || $c === '[' => 1,
            $dois === '>>' || $c === ']' => -1,
            default => 0,
        };

        return $fim === null ? null : [$fim, $mudanca];
    }

    /** Texto entre parênteses, com parênteses aninhados e escapes; pula de uma vez o que não é `\\`, `(` ou `)`. */
    private static function fimDoTexto(string $texto, int $i): ?int
    {
        $profundidade = 0;
        $tamanho = \strlen($texto);
        while ($i < $tamanho) {
            $c = $texto[$i];
            if ($c === ')' && --$profundidade === 0) {
                return $i + 1;
            }
            $profundidade += $c === '(' ? 1 : 0;
            $i = min($tamanho, $i + ($c === '\\' ? 2 : 1));
            $i += strcspn($texto, '\\()', $i);
        }

        return null;
    }

    private static function fimDoHexadecimal(string $texto, int $i): ?int
    {
        $fim = strpos($texto, '>', $i);

        return $fim === false ? null : $fim + 1;
    }
}
