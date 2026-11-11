<?php

declare(strict_types=1);

namespace App\Voto\Service;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** QR do endereço público gerado pelo servidor, com quatro módulos brancos de margem para impressão. */
final class QrCodeDaAssembleia
{
    public function png(string $link): string
    {
        return (new Writer(new GDLibRenderer(1000, 4)))->writeString($link, 'UTF-8', ErrorCorrectionLevel::M());
    }

    public function svg(string $link): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(1000, 4), new SvgImageBackEnd())))
            ->writeString($link, 'UTF-8', ErrorCorrectionLevel::M());
    }
}
