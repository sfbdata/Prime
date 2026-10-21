<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * {@see GeradorDePdf} com o Dompdf: A4 retrato, HTML5, sem acesso remoto, rodapé centralizado
 * a 22 pt da borda de baixo. Extraído do PastaResumoController (o resumo da pasta) para servir
 * também ao relatório geral de processos.
 */
final class GeradorDePdfComDompdf implements GeradorDePdf
{
    private const LOGO = '/public/images/logo/bluejus_simbolo.svg';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function gerar(string $html): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        // A folha é toda do servidor: nada aqui deve buscar recurso externo (o logo vai embutido).
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canvas  = $dompdf->getCanvas();
        $metrica = $dompdf->getFontMetrics();
        $fonte   = $metrica->getFont('Helvetica');
        $largura = $metrica->getTextWidth('BlueJus · página 10 de 10', $fonte, 8);
        $canvas->page_text(
            ($canvas->get_width() - $largura) / 2,
            $canvas->get_height() - 22,
            self::RODAPE,
            $fonte,
            8,
            [123 / 255, 147 / 255, 162 / 255],
        );

        return (string) $dompdf->output();
    }

    public function logoEmbutido(): ?string
    {
        $svg = @file_get_contents($this->projectDir . self::LOGO);

        return $svg !== false ? 'data:image/svg+xml;base64,' . base64_encode($svg) : null;
    }

    public function resposta(string $pdf, string $titulo, \DateTimeImmutable $hoje): Response
    {
        $nome = self::nomeDoArquivo($titulo, $hoje);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'X-Robots-Tag'        => 'noindex',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $nome,
                self::nomeAscii($nome),
            ),
        ]);
    }

    /**
     * "BlueJus <título> dd.mm.aaaa.pdf", como o `pdfBaixar` do desenho: barra vira ponto e os
     * caracteres que o sistema de arquivos recusa (e o "·") viram espaço. Título que já traz a data
     * (dd/mm/aaaa) não ganha outra.
     */
    public static function nomeDoArquivo(string $titulo, \DateTimeImmutable $hoje): string
    {
        $comData = preg_match('#\d{2}/\d{2}/\d{4}#', $titulo) === 1;
        $nome    = 'BlueJus ' . str_replace('/', '.', $titulo) . ($comData ? '' : ' ' . $hoje->format('d.m.Y'));
        $nome    = (string) preg_replace('/[\\\\\/:*?"<>|·]+/u', ' ', $nome);
        $nome    = trim((string) preg_replace('/\s+/u', ' ', $nome));

        return $nome . '.pdf';
    }

    private static function nomeAscii(string $nome): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);

        return (string) preg_replace('/[^\x20-\x7E]|["%\\\\\/]/', '_', $ascii !== false ? $ascii : $nome);
    }
}
