<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Shared\Service\GeradorDePdf;
use App\Voto\DTO\ResumoDoResultado;
use Twig\Environment;

/** PDF do resumo para divulgar (mesma via do comprovante: folha Twig e GeradorDePdf), com o edital anexo ao fim. Não grava arquivo. */
final class PdfDoResumoDoResultado
{
    public function __construct(
        private readonly Environment $twig,
        private readonly GeradorDePdf $pdf,
        private readonly QrCodeDaAssembleia $qr,
        private readonly JuntadorDoEditalAoPdf $juntador,
    ) {
    }

    public function gerar(ResumoDoResultado $resumo): string
    {
        $pdf = $this->pdf->gerar($this->twig->render('voto/assembleia/resumo_resultado_pdf.html.twig', [
            'resumo' => $resumo,
            'qr' => 'data:image/png;base64,' . base64_encode($this->qr->png($resumo->link)),
            'logo' => $this->pdf->logoEmbutido(),
        ]));

        // Com edital anexo, as páginas dele vão ao fim; se a junção falhar, o resultado sai igual (a linha do hash já está na folha).
        return null === $resumo->editalChave ? $pdf : $this->juntador->juntar($pdf, $resumo->editalChave);
    }
}
