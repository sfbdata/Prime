<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Symfony\Component\HttpFoundation\Response;

/**
 * Folha HTML (montada no servidor) para PDF A4, com o rodapé "BlueJus · página k de n" do desenho
 * (`pdfBaixar`, 02 - EXPEDIENTES 1.2.3), e a resposta de download com o nome do desenho.
 */
interface GeradorDePdf
{
    /** Rodapé de cada página (desenho `pdfBaixar`: 8 pt, cinza #7b93a2, centralizado). */
    public const RODAPE = 'BlueJus · página {PAGE_NUM} de {PAGE_COUNT}';

    /** O PDF da folha. Nada de recurso externo: imagens entram embutidas (`data:`). */
    public function gerar(string $html): string;

    /** O símbolo do BlueJus como `data:`, para o cabeçalho da folha (o Dompdf roda sem rede). */
    public function logoEmbutido(): ?string;

    /** Download do PDF, como anexo, com o nome "BlueJus <título> dd.mm.aaaa.pdf". */
    public function resposta(string $pdf, string $titulo, \DateTimeImmutable $hoje): Response;
}
