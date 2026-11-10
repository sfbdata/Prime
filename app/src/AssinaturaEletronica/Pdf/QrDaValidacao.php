<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * O QR do rodapé (`qr` em dc:407): nível M, fundo branco com folga de 4 e módulos azuis com 0,25 a mais de cada
 * lado, para não sobrar fio branco entre eles. A matriz vem do bacon, sem prefixo ECI (como o qrcode-generator do
 * desenho: o endereço é ASCII); a máscara pode diferir da do JS, o conteúdo lido é o mesmo.
 */
final class QrDaValidacao
{
    public function desenhar(Tela $tela, float $x0, float $y0, float $lado, string $conteudo): void
    {
        $matriz = Encoder::encode($conteudo, ErrorCorrectionLevel::M(), 'UTF-8', null, false)->getMatrix();
        $modulos = $matriz->getWidth();
        $u = $lado / $modulos;
        $azul = Cor::hex('#0b3550');

        $tela->retangulo($x0 - 4, $y0 - 4, $lado + 8, $lado + 8, Cor::hex('#ffffff'));
        for ($linha = 0; $linha < $modulos; ++$linha) {
            for ($coluna = 0; $coluna < $modulos; ++$coluna) {
                if ($matriz->get($coluna, $linha) === 1) {
                    $tela->retangulo($x0 + $coluna * $u, $y0 + $linha * $u, $u + .25, $u + .25, $azul);
                }
            }
        }
    }
}
