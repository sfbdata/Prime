<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf;

/**
 * DER à mão para os testes do motor: monta CMS que o `openssl_cms_sign` não faz (RSA-PSS, sem atributos assinados,
 * identificação pela chave) e estruturas maliciosas. Independente do `LeitorDer` do motor, de propósito.
 */
final class MontadorDeDer
{
    public const OID_DADOS = '1.2.840.113549.1.7.1';
    public const OID_DADOS_ASSINADOS = '1.2.840.113549.1.7.2';
    public const OID_TIPO_DE_CONTEUDO = '1.2.840.113549.1.9.3';
    public const OID_RESUMO_DA_MENSAGEM = '1.2.840.113549.1.9.4';
    public const OID_HORA_DA_ASSINATURA = '1.2.840.113549.1.9.5';
    public const OID_SHA256 = '2.16.840.1.101.3.4.2.1';
    public const OID_MD5 = '1.2.840.113549.2.5';
    public const OID_RSA = '1.2.840.113549.1.1.1';
    public const OID_RSA_PSS = '1.2.840.113549.1.1.10';
    public const OID_MGF1 = '1.2.840.113549.1.1.8';

    private function __construct()
    {
    }

    public static function tlv(int $etiqueta, string $valor): string
    {
        $tamanho = \strlen($valor);
        if ($tamanho < 0x80) {
            return \chr($etiqueta) . \chr($tamanho) . $valor;
        }
        $bytes = ltrim(pack('N', $tamanho), "\0");

        return \chr($etiqueta) . \chr(0x80 | \strlen($bytes)) . $bytes . $valor;
    }

    public static function sequencia(string ...$partes): string
    {
        return self::tlv(0x30, implode('', $partes));
    }

    public static function conjunto(string ...$partes): string
    {
        return self::tlv(0x31, implode('', $partes));
    }

    public static function contexto(int $numero, string $valor): string
    {
        return self::tlv(0xA0 | $numero, $valor);
    }

    public static function inteiro(int $valor): string
    {
        return self::tlv(0x02, \chr($valor));
    }

    public static function octetos(string $valor): string
    {
        return self::tlv(0x04, $valor);
    }

    public static function nulo(): string
    {
        return "\x05\x00";
    }

    public static function oid(string $pontos): string
    {
        $arcos = array_map('intval', explode('.', $pontos));
        $bytes = \chr(40 * $arcos[0] + $arcos[1]);
        foreach (\array_slice($arcos, 2) as $arco) {
            $parte = \chr($arco & 0x7F);
            $arco >>= 7;
            while ($arco > 0) {
                $parte = \chr(0x80 | ($arco & 0x7F)) . $parte;
                $arco >>= 7;
            }
            $bytes .= $parte;
        }

        return self::tlv(0x06, $bytes);
    }

    public static function algoritmo(string $oid, ?string $parametros = null): string
    {
        return self::sequencia(self::oid($oid), $parametros ?? self::nulo());
    }

    /**
     * Os elementos de dentro de um elemento construído, cada um com cabeçalho.
     *
     * @return list<string>
     */
    public static function partes(string $der): array
    {
        [$valor] = self::cabecalho($der, 0);
        $fim = $valor + self::tamanho($der, 0);
        $partes = [];
        for ($p = $valor; $p < $fim;) {
            [$inicioDoValor] = self::cabecalho($der, $p);
            $proximo = $inicioDoValor + self::tamanho($der, $p);
            $partes[] = substr($der, $p, $proximo - $p);
            $p = $proximo;
        }

        return $partes;
    }

    /** Tamanho total (cabeçalho e valor) do elemento que começa no byte 0. */
    public static function tamanhoTotal(string $der): int
    {
        [$valor] = self::cabecalho($der, 0);

        return $valor + self::tamanho($der, 0);
    }

    /** @return array{int} */
    private static function cabecalho(string $der, int $p): array
    {
        $tamanho = \ord($der[$p + 1]);

        return [$p + 2 + (($tamanho & 0x80) !== 0 ? $tamanho & 0x7F : 0)];
    }

    private static function tamanho(string $der, int $p): int
    {
        $tamanho = \ord($der[$p + 1]);
        if (($tamanho & 0x80) === 0) {
            return $tamanho;
        }
        $total = 0;
        for ($i = 0; $i < ($tamanho & 0x7F); ++$i) {
            $total = $total * 256 + \ord($der[$p + 2 + $i]);
        }

        return $total;
    }
}
