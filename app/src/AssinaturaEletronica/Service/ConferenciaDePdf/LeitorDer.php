<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Leitura de DER com limites: a porta do `tlv`, `fi`, `val`, `bruto`, `txt` e `dataAsn` do protótipo
 * (`bj-assina-pdf.js`). Toda estrutura vinda de fora entra por `abrir()`, que confere a árvore inteira (tamanhos dentro
 * do pai, profundidade e quantidade de elementos) antes de qualquer navegação. Qualquer desvio lança
 * `FormatoNaoReconhecido` (ou `LimiteExcedido`), que o motor transforma em motivo.
 */
final class LeitorDer
{
    private function __construct()
    {
    }

    /**
     * A estrutura que começa no byte 0. Bytes depois dela são ignorados, como o preenchimento de zeros do /Contents.
     *
     * @throws FormatoNaoReconhecido
     */
    public static function abrir(string $der): NoDer
    {
        $raiz = self::ler($der, 0, \strlen($der));
        $elementos = 0;
        self::validar($der, $raiz, 1, $elementos);

        return $raiz;
    }

    /** @throws FormatoNaoReconhecido */
    public static function ler(string $der, int $posicao, int $limite): NoDer
    {
        if ($posicao < 0 || $posicao + 2 > $limite || $limite > \strlen($der)) {
            throw new FormatoNaoReconhecido('Elemento DER cortado.');
        }

        $etiqueta = \ord($der[$posicao]);
        if (($etiqueta & 0x1F) === 0x1F) {
            throw new FormatoNaoReconhecido('Etiqueta DER de vários bytes.');
        }

        $tamanho = \ord($der[$posicao + 1]);
        $cabecalho = 2;
        if ($tamanho === 0x80) {
            throw new FormatoNaoReconhecido('BER com tamanho indefinido.');
        }
        if (($tamanho & 0x80) !== 0) {
            $bytes = $tamanho & 0x7F;
            if ($bytes > LimitesDaConferencia::BYTES_DE_TAMANHO_MAXIMOS_DER || $posicao + 2 + $bytes > $limite) {
                throw new FormatoNaoReconhecido('Tamanho DER inválido.');
            }
            $tamanho = 0;
            for ($i = 0; $i < $bytes; ++$i) {
                $tamanho = $tamanho * 256 + \ord($der[$posicao + 2 + $i]);
            }
            $cabecalho += $bytes;
        }

        $valor = $posicao + $cabecalho;
        $fim = $valor + $tamanho;
        if ($fim > $limite) {
            throw new FormatoNaoReconhecido('Elemento DER passa do fim do pai.');
        }

        return new NoDer($etiqueta, $posicao, $valor, $fim);
    }

    /**
     * Os filhos de um elemento construído (o `fi` do protótipo).
     *
     * @return list<NoDer>
     *
     * @throws FormatoNaoReconhecido
     */
    public static function filhos(string $der, NoDer $no): array
    {
        if (!$no->construido()) {
            throw new FormatoNaoReconhecido('Elemento primitivo onde se esperava estrutura.');
        }

        $filhos = [];
        $posicao = $no->valor;
        while ($posicao < $no->fim) {
            $filho = self::ler($der, $posicao, $no->fim);
            $filhos[] = $filho;
            if (\count($filhos) > LimitesDaConferencia::ELEMENTOS_MAXIMOS_DER) {
                throw new LimiteExcedido('Elementos DER demais.');
            }
            $posicao = $filho->fim;
        }

        return $filhos;
    }

    /**
     * @param list<NoDer> $lista
     *
     * @throws FormatoNaoReconhecido
     */
    public static function item(array $lista, int $indice): NoDer
    {
        return $lista[$indice] ?? throw new FormatoNaoReconhecido('Elemento DER ausente.');
    }

    /** @throws FormatoNaoReconhecido */
    public static function primeiroFilho(string $der, NoDer $no): NoDer
    {
        return self::item(self::filhos($der, $no), 0);
    }

    public static function valor(string $der, NoDer $no): string
    {
        return substr($der, $no->valor, $no->fim - $no->valor);
    }

    public static function bruto(string $der, NoDer $no): string
    {
        return substr($der, $no->inicio, $no->fim - $no->inicio);
    }

    public static function hex(string $der, NoDer $no): string
    {
        return bin2hex(self::valor($der, $no));
    }

    /**
     * Texto de um elemento de nome (o `txt` do protótipo): BMPString como UTF-16, UTF8String com os bytes inválidos
     * trocados e o resto como Latin-1. Sempre UTF-8 válido, cortado em `CARACTERES_MAXIMOS_DE_NOME`.
     */
    public static function texto(string $der, NoDer $no): string
    {
        $bytes = self::valor($der, $no);
        if ($no->etiqueta === 0x1E) {
            $bytes = substr($bytes, 0, \strlen($bytes) - \strlen($bytes) % 2);
            $texto = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        } elseif ($no->etiqueta === 0x0C) {
            $texto = mb_scrub($bytes, 'UTF-8');
        } else {
            $texto = mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
        }

        return mb_substr($texto, 0, LimitesDaConferencia::CARACTERES_MAXIMOS_DE_NOME, 'UTF-8');
    }

    /**
     * UTCTime (0x17, ano com dois dígitos: menor que 50 é 20xx) ou GeneralizedTime, lido em UTC até os segundos
     * (o `dataAsn` do protótipo). Data impossível lança, como o `Date` inválido do protótipo.
     *
     * @throws FormatoNaoReconhecido
     */
    public static function data(string $der, NoDer $no): \DateTimeImmutable
    {
        $texto = self::valor($der, $no);
        if ($no->etiqueta === 0x17) {
            if (preg_match('/^\d{2}/', $texto) !== 1) {
                throw new FormatoNaoReconhecido('Data ASN.1 inválida.');
            }
            $texto = ((int) substr($texto, 0, 2) < 50 ? '20' : '19') . $texto;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $texto, $partes) !== 1) {
            throw new FormatoNaoReconhecido('Data ASN.1 inválida.');
        }

        return self::dataUtc((int) $partes[1], (int) $partes[2], (int) $partes[3], (int) $partes[4], (int) $partes[5], (int) $partes[6])
            ?? throw new FormatoNaoReconhecido('Data ASN.1 impossível.');
    }

    /** Data em UTC ou null se impossível (mês 13, hora 25). Segundo 60 passa para o minuto seguinte. */
    public static function dataUtc(int $ano, int $mes, int $dia, int $hora, int $minuto, int $segundo): ?\DateTimeImmutable
    {
        if (!checkdate($mes, $dia, $ano) || $hora > 23 || $minuto > 59 || $segundo > 60) {
            return null;
        }

        return (new \DateTimeImmutable('@0'))->setDate($ano, $mes, $dia)->setTime($hora, $minuto, $segundo);
    }

    /** @throws FormatoNaoReconhecido */
    private static function validar(string $der, NoDer $no, int $profundidade, int &$elementos): void
    {
        if (++$elementos > LimitesDaConferencia::ELEMENTOS_MAXIMOS_DER) {
            throw new LimiteExcedido('Elementos DER demais.');
        }
        if (!$no->construido()) {
            return;
        }
        if ($profundidade >= LimitesDaConferencia::PROFUNDIDADE_MAXIMA_DER) {
            throw new LimiteExcedido('DER fundo demais.');
        }

        $posicao = $no->valor;
        while ($posicao < $no->fim) {
            $filho = self::ler($der, $posicao, $no->fim);
            self::validar($der, $filho, $profundidade + 1, $elementos);
            $posicao = $filho->fim;
        }
    }
}
