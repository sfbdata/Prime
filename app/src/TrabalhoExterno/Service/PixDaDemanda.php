<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

/**
 * O "PIX copia e cola" da aba Pagamento: o BR Code do Banco Central (EMV, CRC16-CCITT) do desenho de 25/10
 * (`Component.pix` e `Component.crc16`, l.825), com a chave CNPJ da BlueJus. Sem integração bancária: o txid leva o
 * código da demanda para o Master achar o pagamento no extrato. Serviço novo do Trabalho Externo; o do Contribua
 * (`CodigoPixEstatico`) não é tocado (ele usa o campo 01 = 11, este usa 12, como o desenho).
 */
final class PixDaDemanda
{
    public const CHAVE = '66803163000110';
    public const NOME = 'BLUEJUS GESTAO E TECNOLOG';
    public const CIDADE = 'BRASILIA';
    private const MAX_TXID = 25;

    /** Só letras e números do código da demanda, no máximo 25 (`String(txid).replace(/[^A-Za-z0-9]/g, '').slice(0, 25)`). */
    public static function txid(string $codigoDaDemanda): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9]/', '', $codigoDaDemanda), 0, self::MAX_TXID);
    }

    /**
     * `$valor` é o decimal da proposta ("1500.00"). Valor zero (ou negativo) omite o campo 54: quem paga digita.
     * Quem chama só pede o código com valor maior que zero (a aba não oferece PIX sem proposta aceita).
     */
    public function gerar(string $codigoDaDemanda, string $valor): string
    {
        $contaDoRecebedor = self::campo('00', 'br.gov.bcb.pix') . self::campo('01', self::CHAVE);
        $numero = (float) $valor;
        $carga = self::campo('00', '01')
            . self::campo('01', '12')
            . self::campo('26', $contaDoRecebedor)
            . self::campo('52', '0000')
            . self::campo('53', '986')
            . ($numero > 0 ? self::campo('54', number_format($numero, 2, '.', '')) : '')
            . self::campo('58', 'BR')
            . self::campo('59', self::NOME)
            . self::campo('60', self::CIDADE)
            . self::campo('62', self::campo('05', self::txid($codigoDaDemanda)))
            . '6304';

        return $carga . self::crc16($carga);
    }

    /** CRC16-CCITT (polinômio 0x1021, início 0xFFFF), em 4 hexadecimais maiúsculos. */
    public static function crc16(string $texto): string
    {
        $crc = 0xFFFF;
        foreach (str_split($texto) as $caractere) {
            $crc ^= ord($caractere) << 8;
            for ($i = 0; $i < 8; ++$i) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return str_pad(strtoupper(dechex($crc)), 4, '0', STR_PAD_LEFT);
    }

    private static function campo(string $id, string $valor): string
    {
        return $id . str_pad((string) strlen($valor), 2, '0', STR_PAD_LEFT) . $valor;
    }
}
