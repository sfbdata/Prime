<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

/**
 * Interna do `LocalizadorDaUltimaPagina`: lê o primeiro nível de um dicionário do PDF (nome => valor como está no
 * texto), pulando espaços e comentários, com textos, hexadecimais e grupos aninhados dentro dos valores.
 *
 * Cada passo (uma entrada, um pedaço de vetor ou dicionário, uma volta num texto ou num comentário) entra no trabalho
 * da procura, e cada entrada guardada entra na memória guardada, antes de ser guardada (`CarimboVisivelObjetos`).
 * O resto do texto é pulado de uma vez, por funções nativas.
 */
final class CarimboVisivelDicionario
{
    /**
     * Estimativa da memória de uma entrada guardada, além do texto do nome e do valor: a chave, o vetor de três
     * posições e o espaço na tabela do PHP (medido: ~340 bytes).
     */
    public const ENTRADA_EM_BYTES = 384;

    private const ESPACOS = " \t\r\n\f\0";
    private const DELIMITADORES = " \t\r\n\f\0()<>[]{}/%";
    /** O que muda a leitura dentro de um vetor ou dicionário: o resto é pulado de uma vez. */
    private const ESPECIAIS_DO_GRUPO = '()<>[]%';

    public function __construct(
        private readonly CarimboVisivelObjetos $objetos,
    ) {
    }

    /**
     * O primeiro nível de um dicionário: nome => [valor como está no texto, início do nome, fim do valor]. Nome
     * repetido fica com o último valor, como no pdf.js. Null se o texto não começa com um dicionário completo.
     *
     * @return array<string, array{string, int, int}>|null
     */
    public function entradas(string $texto): ?array
    {
        $i = $this->depoisDosEspacos($texto, 0);
        if (substr($texto, $i, 2) !== '<<') {
            return null;
        }
        $entradas = [];
        $i = $this->depoisDosEspacos($texto, $i + 2);
        while (($texto[$i] ?? '') === '/') {
            $this->objetos->passos();
            $fimDoNome = $i + 1 + strcspn($texto, self::DELIMITADORES, $i + 1);
            $inicioDoValor = $this->depoisDosEspacos($texto, $fimDoNome);
            $fim = $this->fimDoValor($texto, $inicioDoValor);
            if ($fim === null) {
                return null;
            }
            $this->objetos->guardar(self::ENTRADA_EM_BYTES + ($fimDoNome - $i) + ($fim - $inicioDoValor));
            $entradas[substr($texto, $i, $fimDoNome - $i)] = [substr($texto, $inicioDoValor, $fim - $inicioDoValor), $i, $fim];
            $i = $this->depoisDosEspacos($texto, $fim);
        }

        return substr($texto, $i, 2) === '>>' ? $entradas : null;
    }

    /** Pula espaços e comentários; cada comentário é um passo. */
    public function depoisDosEspacos(string $texto, int $i): int
    {
        while (true) {
            $i += strspn($texto, self::ESPACOS, $i);
            if (($texto[$i] ?? '') !== '%') {
                return $i;
            }
            $this->objetos->passos();
            $i += strcspn($texto, "\r\n", $i);
        }
    }

    /** Onde acaba o valor que começa em `$i`; null se ali não começa um valor. */
    private function fimDoValor(string $texto, int $i): ?int
    {
        $c = $texto[$i] ?? '';

        return match (true) {
            $c === '' || str_contains(')>]{}', $c) => null,
            $c === '(' => $this->fimDoTexto($texto, $i),
            $c === '<' && ($texto[$i + 1] ?? '') !== '<' => self::fimDoHexadecimal($texto, $i),
            $c === '<' || $c === '[' => $this->fimDoGrupo($texto, $i),
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
    private function fimDoGrupo(string $texto, int $i): ?int
    {
        $profundidade = 0;
        $tamanho = \strlen($texto);
        while ($i < $tamanho) {
            $this->objetos->passos();
            $passo = $this->passoNoGrupo($texto, $i);
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
    private function passoNoGrupo(string $texto, int $i): ?array
    {
        $dois = substr($texto, $i, 2);
        $c = $texto[$i];
        $fim = match (true) {
            $dois === '<<' || $dois === '>>' => $i + 2,
            $c === '[' || $c === ']' => $i + 1,
            $c === '%' => $i + strcspn($texto, "\r\n", $i),
            $c === '(' => $this->fimDoTexto($texto, $i),
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

    /**
     * Texto entre parênteses, com parênteses aninhados e escapes; pula de uma vez o que não é `\\`, `(` ou `)`, e
     * cada volta é um passo.
     */
    private function fimDoTexto(string $texto, int $i): ?int
    {
        $profundidade = 0;
        $tamanho = \strlen($texto);
        while ($i < $tamanho) {
            $this->objetos->passos();
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
