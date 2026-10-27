<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * O QR Code do PIX em SVG, gerado no servidor (decisão do coordenador de 25/10: sem CDN, sem GD). Nível de correção
 * "M" e sem margem, como o desenho (`createSvgTag({cellSize: 4, margin: 0, scalable: true})`); o SVG enche o quadro
 * de 170x170 da aba (`width:100%;height:100%`).
 *
 * O SVG sai SÓ do texto que o servidor monta (o BR Code da proposta aceita): nenhuma entrada do usuário entra nele,
 * então a tela pode injetá-lo como marcação.
 */
final class QrCodeEmSvg
{
    public function gerar(string $conteudo): string
    {
        $escritor = new Writer(new ImageRenderer(new RendererStyle(170, 0), new SvgImageBackEnd()));
        $svg = $escritor->writeString($conteudo, 'UTF-8', ErrorCorrectionLevel::M());
        // Sem o prólogo XML e com o desenho preenchendo o quadro do desenho.
        $svg = (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', trim($svg));

        return (string) preg_replace('/^<svg\b[^>]*>/', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 170 170" style="width:100%;height:100%" shape-rendering="crispEdges">', $svg, 1);
    }
}
