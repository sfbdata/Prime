<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\AtaDaAssembleia;
use Twig\Environment;

/**
 * O corpo da minuta de ata em HTML bem formado (a regra do texto mora em _minuta_ata_corpo.html.twig) e a folha do PDF.
 * Os campos que o sistema não sabe ([[assim]]) viram `<mark>[assim]</mark>`, o destaque amarelo do desenho.
 */
final class HtmlDaMinutaDeAta
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function corpo(AtaDaAssembleia $ata): string
    {
        $html = $this->twig->render('voto/assembleia/_minuta_ata_corpo.html.twig', ['ata' => $ata]);
        // Caractere que o XML 1.0 não admite (vindo de um título colado) derrubaria a leitura do corpo.
        $html = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $html);

        return (string) preg_replace('/\[\[([^\]]*)\]\]/', '<mark>[$1]</mark>', $html);
    }

    public function folhaDoPdf(AtaDaAssembleia $ata): string
    {
        return $this->twig->render('voto/assembleia/minuta_ata_pdf.html.twig', ['corpo' => $this->corpo($ata)]);
    }
}
