<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Shared\Service\GeradorDePdf;
use App\Voto\DTO\ResumoDoResultado;
use Twig\Environment;

/** PDF do resumo para divulgar (mesma via do comprovante: folha Twig e GeradorDePdf). Não grava arquivo. */
final class PdfDoResumoDoResultado
{
    public function __construct(
        private readonly Environment $twig,
        private readonly GeradorDePdf $pdf,
        private readonly QrCodeDaAssembleia $qr,
    ) {
    }

    public function gerar(ResumoDoResultado $resumo): string
    {
        return $this->pdf->gerar($this->twig->render('voto/assembleia/resumo_resultado_pdf.html.twig', [
            'resumo' => $resumo,
            'qr' => 'data:image/png;base64,' . base64_encode($this->qr->png($resumo->link)),
            'logo' => $this->pdf->logoEmbutido(),
        ]));
    }
}
