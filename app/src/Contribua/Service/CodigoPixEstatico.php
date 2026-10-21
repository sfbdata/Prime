<?php

declare(strict_types=1);

namespace App\Contribua\Service;

/**
 * O "PIX copia e cola" estático da BlueJus (padrão BR Code do Banco Central, EMV com CRC16-CCITT),
 * o mesmo que o desenho 08 gera no navegador. Funciona sem integração bancária nenhuma: a chave é o
 * CNPJ e o txid leva o número da conta do escritório, para o Master identificar no extrato quem apoiou.
 */
final class CodigoPixEstatico
{
    public const CHAVE = '66803163000110';
    public const NOME = 'BLUEJUS GESTAO E TECNOLOG';
    public const CIDADE = 'BRASILIA';

    /** `$valorCentavos` 0 = valor livre (quem paga digita). */
    public static function gerar(string $txid, int $valorCentavos): string
    {
        $contaDoRecebedor = self::campo('00', 'br.gov.bcb.pix') . self::campo('01', self::CHAVE);
        $carga = self::campo('00', '01')
            . self::campo('01', '11')
            . self::campo('26', $contaDoRecebedor)
            . self::campo('52', '0000')
            . self::campo('53', '986')
            . ($valorCentavos > 0 ? self::campo('54', number_format($valorCentavos / 100, 2, '.', '')) : '')
            . self::campo('58', 'BR')
            . self::campo('59', self::NOME)
            . self::campo('60', self::CIDADE)
            . self::campo('62', self::campo('05', $txid))
            . '6304';

        return $carga . self::crc16($carga);
    }

    /** Confere a estrutura e o CRC (o selo "PIX válido" do desenho). */
    public static function valido(string $codigo): bool
    {
        $campos = self::ler($codigo);
        if ($campos === null) {
            return false;
        }
        $campo = static fn (string $id): string => $campos[$id] ?? '';

        return $campo('00') === '01'
            && str_contains($campo('26'), 'br.gov.bcb.pix')
            && $campo('53') === '986'
            && $campo('58') === 'BR'
            && substr($codigo, -4) === self::crc16(substr($codigo, 0, -4));
    }

    /** O txid do escritório: "BJ" + número da conta. */
    public static function txidDaConta(string $numeroDaConta): string
    {
        return 'BJ' . $numeroDaConta;
    }

    private static function campo(string $id, string $valor): string
    {
        return $id . str_pad((string) \strlen($valor), 2, '0', \STR_PAD_LEFT) . $valor;
    }

    /** @return array<string, string>|null */
    private static function ler(string $codigo): ?array
    {
        $campos = [];
        for ($i = 0; $i < \strlen($codigo) - 4;) {
            $id = substr($codigo, $i, 2);
            $tamanho = substr($codigo, $i + 2, 2);
            if (preg_match('/^\d{4}$/', $id . $tamanho) !== 1) {
                return null;
            }
            $campos[$id] = substr($codigo, $i + 4, (int) $tamanho);
            $i += 4 + (int) $tamanho;
            if ($id === '63') {
                break;
            }
        }

        return $campos;
    }

    private static function crc16(string $dados): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = \strlen($dados); $i < $n; ++$i) {
            $crc ^= \ord($dados[$i]) << 8;
            for ($bit = 0; $bit < 8; ++$bit) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return str_pad(strtoupper(dechex($crc)), 4, '0', \STR_PAD_LEFT);
    }
}
