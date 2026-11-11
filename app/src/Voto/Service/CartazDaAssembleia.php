<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\DivulgacaoDaAssembleiaOutput;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

/** Cartaz 1000x1414: medidas, cores, posições e textos de baixarQr() do desenho. */
final class CartazDaAssembleia
{
    private const FONTES = '/usr/share/fonts/opentype/urw-base35/';

    public function gerar(DivulgacaoDaAssembleiaOutput $tela): string
    {
        $imagem = imagecreatetruecolor(1000, 1414);
        if ($imagem === false) {
            throw new \RuntimeException('Não foi possível gerar o cartaz.');
        }
        try {
            imagefilledrectangle($imagem, 0, 0, 1000, 1414, 0xffffff);
            imagefilledrectangle($imagem, 0, 0, 1000, 170, 0x0a6fa0);
            $this->texto($imagem, 'Vote pelo celular', 72, true, 0xffffff, 112);
            $this->texto($imagem, $this->titulo($tela->titulo), 40, true, 0x0b3550, 240);
            $this->desenharQr($imagem, $tela->link);
            $this->texto($imagem, 'Aponte a câmera para o código', 42, true, 0x0b3550, 1044);
            $this->texto($imagem, 'Não precisa ter conta no BlueJus', 34, true, 0x1e7b45, 1098);
            $this->texto($imagem, '1. Localize seu nome   2. Confirme sua identidade   3. Vote', 30, false, 0x34505f, 1160);
            $this->texto($imagem, $tela->link, 26, false, 0x34505f, 1226);
            $this->texto($imagem, 'Confira o código: ' . $tela->codigoDeConferencia(), 32, true, 0x0b3550, 1280);
            $this->texto($imagem, 'A identificação continua exigida para votar.', 24, false, 0x8a6200, 1350);

            return $this->png($imagem);
        } finally {
            imagedestroy($imagem);
        }
    }

    private function desenharQr(\GdImage $imagem, string $link): void
    {
        $bytes = (new Writer(new GDLibRenderer(720, 4)))->writeString($link, 'UTF-8', ErrorCorrectionLevel::M());
        $qr = imagecreatefromstring($bytes);
        if ($qr === false) {
            throw new \RuntimeException('Não foi possível gerar o QR Code.');
        }
        imagecopy($imagem, $qr, 140, 268, 0, 0, 720, 720);
        imagedestroy($qr);
    }

    private function texto(\GdImage $imagem, string $texto, float $pixels, bool $negrito, int $cor, int $y): void
    {
        $fonte = self::FONTES . ($negrito ? 'NimbusSans-Bold.otf' : 'NimbusSans-Regular.otf');
        $tamanho = $pixels * .75;
        $caixa = imagettfbbox($tamanho, 0, $fonte, $texto);
        while ($caixa !== false && $caixa[2] - $caixa[0] > 940 && $tamanho > 8) {
            $caixa = imagettfbbox(--$tamanho, 0, $fonte, $texto);
        }
        if ($caixa === false) {
            throw new \RuntimeException('Não foi possível medir o texto do cartaz.');
        }
        imagettftext($imagem, $tamanho, 0, (int) ((1000 - ($caixa[2] - $caixa[0])) / 2), $y, $cor, $fonte, $texto);
    }

    private function titulo(string $texto): string
    {
        $curto = $texto;
        $fonte = self::FONTES . 'NimbusSans-Bold.otf';
        while (mb_strlen($curto) > 1) {
            $caixa = imagettfbbox(30, 0, $fonte, $curto);
            if ($caixa !== false && $caixa[2] - $caixa[0] <= 900) {
                break;
            }
            $curto = mb_substr($curto, 0, -2);
        }

        return $curto === $texto ? $texto : rtrim($curto) . '...';
    }

    private function png(\GdImage $imagem): string
    {
        ob_start();
        try {
            if (!imagepng($imagem)) {
                throw new \RuntimeException('Não foi possível gerar a imagem do cartaz.');
            }

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
