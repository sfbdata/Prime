<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Leitura da sintaxe do PDF para a análise do acréscimo (M27): cabeçalho `N G obj`, dicionário com os valores
 * normalizados e o decodificador dos fluxos de objetos e de índice.
 *
 * O dicionário volta como `chave => valor`, com cada valor reescrito com um espaço entre as partes (textos e
 * hexadecimais como estão), para comparar duas versões de um objeto sem se importar com espaços e comentários. Chave
 * repetida, sintaxe que não fecha ou aninhamento fundo demais lançam `FormatoNaoReconhecido`. Sem regex que volta atrás
 * e sem cópia do arquivo: tudo é lido pela posição.
 *
 * Uma instância por conferência: ela soma o trabalho (bytes lidos, copiados e inflados) e os elementos lidos, e lança
 * `LimiteExcedido` ao passar dos tetos, para um arquivo montado para gastar CPU parar cedo.
 */
final class LeitorDeSintaxeDoPdf
{
    private int $trabalho = 0;
    private int $elementos = 0;

    public const ESPACOS = "\0\t\n\f\r ";
    private const DELIMITADORES = '()<>[]{}/%';
    private const PROFUNDIDADE_MAXIMA = 32;

    /**
     * Cabeçalho de objeto como os leitores aceitam: espaço ou comentário entre as partes. Os números podem vir com
     * sinal ou ponto, para o motor recusar depois em vez de não ver o objeto.
     */
    public const PADRAO_DO_CABECALHO = '(?<![0-9.+\-])([+\-]?[0-9.]++)(?:[\0\t\n\f\r ]|%[^\r\n]*+)++([+\-]?[0-9.]++)(?:[\0\t\n\f\r ]|%[^\r\n]*+)*+obj(?![A-Za-z0-9])';

    /**
     * Número e geração (se forem inteiros comuns) e a posição depois do `obj` do cabeçalho que começa em `$posicao`.
     *
     * @return array{int, int, int}|null
     */
    public static function cabecalhoEm(string $pdf, int $posicao): ?array
    {
        if (preg_match('/\G' . self::PADRAO_DO_CABECALHO . '/', $pdf, $partes, 0, $posicao) !== 1) {
            return null;
        }
        $numeros = self::numerosDoCabecalho($partes[1], $partes[2]);

        return $numeros === null ? null : [$numeros[0], $numeros[1], $posicao + \strlen($partes[0])];
    }

    /**
     * Os dois números do cabeçalho, se forem inteiros sem sinal e sem ponto.
     *
     * @return array{int, int}|null
     */
    public static function numerosDoCabecalho(string $numero, string $geracao): ?array
    {
        if (!ctype_digit($numero) || !ctype_digit($geracao) || \strlen($numero) > 10 || \strlen($geracao) > 5) {
            return null;
        }

        return [(int) $numero, (int) $geracao];
    }

    /**
     * Dicionário que começa em `$inicio` (depois de espaços e comentários), ou null se ali não começa um `<<`.
     * `$fim` recebe a posição depois do `>>`.
     *
     * @return array<string, string>|null
     *
     * @throws FormatoNaoReconhecido
     */
    public function dicionario(string $texto, int $inicio, int &$fim, ?int $limite = null): ?array
    {
        $this->trabalhar(0);
        $limite ??= \strlen($texto);
        $posicao = self::pularEspacos($texto, $inicio, $limite);
        if (substr($texto, $posicao, 2) !== '<<') {
            return null;
        }
        try {
            $entradas = $this->lerDicionario($texto, $posicao, $limite, 1);
        } finally {
            $this->trabalho += $posicao - $inicio;
        }
        $fim = $posicao;

        return $entradas;
    }

    /**
     * Um valor qualquer (vetor, número, texto, referência) que começa em `$inicio`, normalizado como os do dicionário.
     * Null se não der para ler; passar dos tetos lança. `$fim` recebe a posição depois do valor.
     *
     * @throws LimiteExcedido
     */
    public function valorEm(string $texto, int $inicio, int &$fim, ?int $limite = null): ?string
    {
        $this->trabalhar(0);
        $limite ??= \strlen($texto);
        $posicao = $inicio;
        try {
            $valor = $this->valor($texto, $posicao, $limite, 1);
        } catch (LimiteExcedido $e) {
            throw $e;
        } catch (FormatoNaoReconhecido) {
            return null;
        } finally {
            $this->trabalho += max(0, $posicao - $inicio);
        }
        $fim = $posicao;

        return $valor;
    }

    /**
     * Soma trabalho e lança ao passar do teto.
     *
     * @throws LimiteExcedido
     */
    public function trabalhar(int $bytes): void
    {
        $this->trabalho += $bytes;
        if ($this->trabalho > LimitesDaConferencia::TRABALHO_MAXIMO_EM_BYTES) {
            throw new LimiteExcedido('Trabalho demais na leitura do PDF.');
        }
    }

    public function trabalhoRestante(): int
    {
        return max(0, LimitesDaConferencia::TRABALHO_MAXIMO_EM_BYTES - $this->trabalho);
    }

    /**
     * Os números dos objetos de um vetor só de referências (`[ 4 0 R 6 0 R ]`, já normalizado). Null se não for.
     *
     * @return list<int>|null
     */
    public static function referencias(string $valor): ?array
    {
        if (preg_match('/^\[(?: \d++ \d++ R)*+ \]$/', $valor) !== 1) {
            return null;
        }
        preg_match_all('/ (\d++) \d++ R/', $valor, $partes);

        return array_map('intval', $partes[1]);
    }

    /** O número de uma referência sozinha (`7 0 R`); null se o valor não for uma. */
    public static function referencia(string $valor): ?int
    {
        return preg_match('/^(\d++) \d++ R$/', $valor, $partes) === 1 ? (int) $partes[1] : null;
    }

    /**
     * Os dados do fluxo já decodificados: sem filtro ou FlateDecode, com o preditor PNG dos fluxos de índice. Outro
     * filtro ou outro preditor devolvem null; passar do teto de trabalho lança.
     *
     * @throws FormatoNaoReconhecido
     */
    public function dados(string $pdf, ObjetoDoPdf $objeto): ?string
    {
        $this->trabalhar($objeto->fimDosDados - $objeto->inicioDosDados);
        $dados = substr($pdf, $objeto->inicioDosDados, $objeto->fimDosDados - $objeto->inicioDosDados);
        $filtro = $objeto->valor('/Filter');
        if ($filtro === null) {
            return $dados;
        }
        if ($filtro !== '/FlateDecode' && $filtro !== '[ /FlateDecode ]') {
            return null;
        }

        $inflado = InfladorLimitado::inflar(rtrim($dados, "\r\n"), min(LimitesDaConferencia::INFLADO_MAXIMO_EM_BYTES, $this->trabalhoRestante()));
        if ($inflado === null) {
            return null;
        }
        $this->trabalhar(\strlen($inflado));

        $parametros = $objeto->valor('/DecodeParms');
        if ($parametros === null) {
            return $inflado;
        }
        $fim = 0;
        $parametros = $this->dicionario($parametros, 0, $fim);
        if ($parametros === null) {
            return null;
        }
        $preditor = (int) ($parametros['/Predictor'] ?? '1');
        if ($preditor === 1) {
            return $inflado;
        }
        $cores = (int) ($parametros['/Colors'] ?? '1');
        $bits = (int) ($parametros['/BitsPerComponent'] ?? '8');
        if ($preditor < 10 || $cores !== 1 || $bits !== 8) {
            return null;
        }

        return self::semPreditorPng($inflado, (int) ($parametros['/Columns'] ?? '1'));
    }

    /**
     * Os objetos de um /ObjStm, por número. Objeto que não é dicionário vem com o valor em `conteudo`.
     *
     * @return array<int, ObjetoDoPdf>|null
     *
     * @throws FormatoNaoReconhecido
     */
    public function objetosDoFluxo(string $dados, ObjetoDoPdf $fluxo): ?array
    {
        $quantidade = $fluxo->valor('/N');
        $primeiro = $fluxo->valor('/First');
        if ($quantidade === null || $primeiro === null || !ctype_digit($quantidade) || !ctype_digit($primeiro)
            || (int) $quantidade > LimitesDaConferencia::OBJETOS_MAXIMOS || (int) $primeiro > \strlen($dados)) {
            return null;
        }
        $quantidade = (int) $quantidade;
        $primeiro = (int) $primeiro;

        $cabecalho = preg_split('/[\0\t\n\f\r ]++/', trim(substr($dados, 0, $primeiro), self::ESPACOS), 2 * $quantidade + 1);
        if ($cabecalho === false || \count($cabecalho) < 2 * $quantidade) {
            return null;
        }

        $objetos = [];
        for ($i = 0; $i < $quantidade; ++$i) {
            $numero = $cabecalho[2 * $i];
            $deslocamento = $cabecalho[2 * $i + 1];
            if (!ctype_digit($numero) || !ctype_digit($deslocamento) || isset($objetos[(int) $numero])) {
                return null;
            }
            $fim = 0;
            $dicionario = $this->dicionario($dados, $primeiro + (int) $deslocamento, $fim);
            $conteudo = $dicionario === null ? $this->valorEm($dados, $primeiro + (int) $deslocamento, $fim) : null;
            $objetos[(int) $numero] = new ObjetoDoPdf((int) $numero, 0, $dicionario, conteudo: $conteudo);
        }

        return $objetos;
    }

    public static function pularEspacos(string $texto, int $posicao, ?int $limite = null): int
    {
        $limite ??= \strlen($texto);
        while ($posicao < $limite) {
            $posicao += strspn($texto, self::ESPACOS, $posicao, $limite - $posicao);
            if ($posicao >= $limite || $texto[$posicao] !== '%') {
                break;
            }
            $posicao += strcspn($texto, "\r\n", $posicao, $limite - $posicao);
        }

        return min($posicao, $limite);
    }

    /**
     * @return array<string, string>
     *
     * @throws FormatoNaoReconhecido
     */
    private function lerDicionario(string $texto, int &$posicao, int $limite, int $nivel): array
    {
        if ($nivel > self::PROFUNDIDADE_MAXIMA) {
            throw new LimiteExcedido('Dicionário fundo demais.');
        }
        $posicao += 2;
        $entradas = [];
        while (true) {
            $posicao = self::pularEspacos($texto, $posicao, $limite);
            if ($posicao >= $limite) {
                throw new FormatoNaoReconhecido('Dicionário sem fim.');
            }
            if (substr($texto, $posicao, 2) === '>>') {
                $posicao += 2;

                return $entradas;
            }
            if ($texto[$posicao] !== '/') {
                throw new FormatoNaoReconhecido('Chave de dicionário que não é nome.');
            }
            $chave = self::nome($texto, $posicao, $limite);
            if (isset($entradas[$chave])) {
                throw new FormatoNaoReconhecido('Chave repetida no dicionário.');
            }
            $entradas[$chave] = $this->valor($texto, $posicao, $limite, $nivel + 1);
        }
    }

    /** @throws FormatoNaoReconhecido */
    private function valor(string $texto, int &$posicao, int $limite, int $nivel): string
    {
        if ($nivel > self::PROFUNDIDADE_MAXIMA) {
            throw new LimiteExcedido('Valor fundo demais.');
        }
        if (++$this->elementos > LimitesDaConferencia::ELEMENTOS_MAXIMOS_DA_SINTAXE) {
            throw new LimiteExcedido('Elementos demais na leitura do PDF.');
        }
        $posicao = self::pularEspacos($texto, $posicao, $limite);
        if ($posicao >= $limite) {
            throw new FormatoNaoReconhecido('Valor sem fim.');
        }

        $caractere = $texto[$posicao];
        if ($caractere === '<' && ($texto[$posicao + 1] ?? '') === '<') {
            return $this->dicionarioComoValor($texto, $posicao, $limite, $nivel);
        }

        return match ($caractere) {
            '[' => $this->vetor($texto, $posicao, $limite, $nivel),
            '(' => self::textoLiteral($texto, $posicao, $limite),
            '<' => self::hexadecimal($texto, $posicao, $limite),
            '/' => self::nome($texto, $posicao, $limite),
            default => self::tokenOuReferencia($texto, $posicao, $limite),
        };
    }

    /** @throws FormatoNaoReconhecido */
    private function dicionarioComoValor(string $texto, int &$posicao, int $limite, int $nivel): string
    {
        $partes = [];
        foreach ($this->lerDicionario($texto, $posicao, $limite, $nivel) as $chave => $valor) {
            $partes[] = $chave . ' ' . $valor;
        }

        return '<<' . ($partes === [] ? '' : ' ' . implode(' ', $partes)) . ' >>';
    }

    /** @throws FormatoNaoReconhecido */
    private function vetor(string $texto, int &$posicao, int $limite, int $nivel): string
    {
        ++$posicao;
        $itens = [];
        while (true) {
            $posicao = self::pularEspacos($texto, $posicao, $limite);
            if ($posicao >= $limite) {
                throw new FormatoNaoReconhecido('Vetor sem fim.');
            }
            if ($texto[$posicao] === ']') {
                ++$posicao;

                return '[' . ($itens === [] ? '' : ' ' . implode(' ', $itens)) . ' ]';
            }
            $itens[] = $this->valor($texto, $posicao, $limite, $nivel + 1);
        }
    }

    /** @throws FormatoNaoReconhecido */
    private static function hexadecimal(string $texto, int &$posicao, int $limite): string
    {
        $fim = strpos($texto, '>', $posicao);
        if ($fim === false || $fim >= $limite) {
            throw new FormatoNaoReconhecido('Hexadecimal sem fim.');
        }
        $valor = substr($texto, $posicao, $fim + 1 - $posicao);
        $posicao = $fim + 1;

        return $valor;
    }

    /**
     * Número, palavra ou referência indireta: `N G R` é um valor só.
     *
     * @throws FormatoNaoReconhecido
     */
    private static function tokenOuReferencia(string $texto, int &$posicao, int $limite): string
    {
        $token = self::token($texto, $posicao, $limite);
        if (!ctype_digit($token)) {
            return $token;
        }
        $segunda = self::pularEspacos($texto, $posicao, $limite);
        $geracao = $segunda < $limite ? self::tokenOuVazio($texto, $segunda, $limite) : '';
        if ($geracao !== '' && ctype_digit($geracao)) {
            $terceira = self::pularEspacos($texto, $segunda, $limite);
            if ($terceira < $limite && self::tokenOuVazio($texto, $terceira, $limite) === 'R') {
                $posicao = $terceira;

                return $token . ' ' . $geracao . ' R';
            }
        }

        return $token;
    }

    /** @throws FormatoNaoReconhecido */
    private static function token(string $texto, int &$posicao, int $limite): string
    {
        $token = self::tokenOuVazio($texto, $posicao, $limite);
        if ($token === '') {
            throw new FormatoNaoReconhecido('Valor que não dá para ler.');
        }

        return $token;
    }

    private static function tokenOuVazio(string $texto, int &$posicao, int $limite): string
    {
        $tamanho = strcspn($texto, self::ESPACOS . self::DELIMITADORES, $posicao, $limite - $posicao);
        $token = substr($texto, $posicao, $tamanho);
        $posicao += $tamanho;

        return $token;
    }

    private static function nome(string $texto, int &$posicao, int $limite): string
    {
        $tamanho = 1 + strcspn($texto, self::ESPACOS . self::DELIMITADORES, $posicao + 1, max(0, $limite - $posicao - 1));
        $nome = substr($texto, $posicao, $tamanho);
        $posicao += $tamanho;

        return $nome;
    }

    /** @throws FormatoNaoReconhecido */
    private static function textoLiteral(string $texto, int &$posicao, int $limite): string
    {
        $inicio = $posicao;
        $profundidade = 0;
        while ($posicao < $limite) {
            $posicao += strcspn($texto, '\\()', $posicao, $limite - $posicao);
            if ($posicao >= $limite) {
                break;
            }
            $caractere = $texto[$posicao];
            if ($caractere === '\\') {
                $posicao += 2;
                continue;
            }
            ++$posicao;
            $profundidade += $caractere === '(' ? 1 : -1;
            if ($profundidade === 0) {
                return substr($texto, $inicio, $posicao - $inicio);
            }
        }

        throw new FormatoNaoReconhecido('Texto sem fim.');
    }

    /** Desfaz o preditor PNG (uma cor, 8 bits): cada linha começa com o byte do filtro. */
    private static function semPreditorPng(string $dados, int $colunas): ?string
    {
        if ($colunas < 1 || $colunas > 64) {
            return null;
        }
        $linha = $colunas + 1;
        if (\strlen($dados) % $linha !== 0) {
            return null;
        }

        $saida = '';
        $anterior = str_repeat("\0", $colunas);
        for ($inicio = 0, $total = \strlen($dados); $inicio < $total; $inicio += $linha) {
            $filtro = \ord($dados[$inicio]);
            $atual = substr($dados, $inicio + 1, $colunas);
            for ($i = 0; $i < $colunas; ++$i) {
                $esquerda = $i > 0 ? \ord($atual[$i - 1]) : 0;
                $cima = \ord($anterior[$i]);
                $diagonal = $i > 0 ? \ord($anterior[$i - 1]) : 0;
                $soma = match ($filtro) {
                    0 => 0,
                    1 => $esquerda,
                    2 => $cima,
                    3 => intdiv($esquerda + $cima, 2),
                    4 => self::paeth($esquerda, $cima, $diagonal),
                    default => -1,
                };
                if ($soma < 0) {
                    return null;
                }
                $atual[$i] = \chr((\ord($atual[$i]) + $soma) & 0xFF);
            }
            $saida .= $atual;
            $anterior = $atual;
        }

        return $saida;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }

        return $pb <= $pc ? $b : $c;
    }
}
