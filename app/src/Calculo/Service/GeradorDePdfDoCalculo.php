<?php

declare(strict_types=1);

namespace App\Calculo\Service;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF do demonstrativo (desenho 14): A4 retrato, sem recurso remoto (o logo vai embutido), com a faixa de
 * cima (emissão e "BlueJus · Cálculos") e o rodapé com o código de verificação e a página, como no exemplo.
 * O {@see \App\Shared\Service\GeradorDePdf} geral não serve: ele imprime o rodapé dele.
 */
final class GeradorDePdfDoCalculo
{
    public function gerar(string $html, string $emitidoEm, string $cabecalho, string $rodape): string
    {
        $opcoes = new Options();
        $opcoes->set('isHtml5ParserEnabled', true);
        $opcoes->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($opcoes);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $fonte = $dompdf->getFontMetrics()->getFont('Helvetica');
        $cinza = [73 / 255, 80 / 255, 87 / 255];
        $largura = $canvas->get_width();
        $metrica = $dompdf->getFontMetrics();
        $margem = 34.0;

        $canvas->page_text($margem, 22, $emitidoEm, $fonte, 7, $cinza);
        $canvas->page_text(($largura - $metrica->getTextWidth($cabecalho, $fonte, 7)) / 2, 22, $cabecalho, $fonte, 7, $cinza);
        $canvas->page_text($margem, $canvas->get_height() - 28, $rodape, $fonte, 7, $cinza);
        $canvas->page_text($largura - $margem - 16, $canvas->get_height() - 28, '{PAGE_NUM}/{PAGE_COUNT}', $fonte, 7, $cinza);

        return (string) $dompdf->output();
    }

    /** "BJC-" + 8 caracteres base 36 maiúsculos, como o código do exemplo (BJC-MVV219D2). */
    public static function codigoDeVerificacao(): string
    {
        return 'BJC-' . strtoupper(substr(str_pad(base_convert((string) random_int(0, 36 ** 8 - 1), 10, 36), 8, '0', \STR_PAD_LEFT), -8));
    }
}
