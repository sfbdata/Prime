<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\PastaDocumento;
use App\Pasta\DTO\ExportarPecaTextoOutput;
use App\Shared\Service\ArquivoStorageInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExportarPecaTextoUseCase
{
    public function __construct(
        private readonly ArquivoStorageInterface $storage,
        private readonly string $uploadsDir,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function executar(PastaDocumento $doc, string $formato): ExportarPecaTextoOutput
    {
        $caminho = $this->storage->caminho($this->uploadsDir, $doc->getCaminhoArquivo());
        $html    = (string) file_get_contents($caminho);

        $htmlExport = str_replace(
            '/uploads/',
            $this->projectDir . '/public/uploads/',
            $html,
        );

        return match ($formato) {
            'docx'  => $this->gerarDocx($doc, $htmlExport),
            'odt'   => $this->gerarOdt($doc, $htmlExport),
            'pdf'   => $this->gerarPdf($doc, $htmlExport),
            'txt'   => $this->gerarTxt($doc, $html),
            default => throw new \InvalidArgumentException("Formato não suportado: {$formato}"),
        };
    }

    private function sanitizarParaXhtml(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $body = $doc->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return $html;
        }

        $saida = '';
        foreach ($body->childNodes as $node) {
            $saida .= $doc->saveXML($node);
        }

        return $saida ?: $html;
    }

    private function gerarDocx(PastaDocumento $doc, string $html): ExportarPecaTextoOutput
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        Html::addHtml($section, $this->sanitizarParaXhtml($html), false, false);

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        ob_start();
        $writer->save('php://output');
        $conteudo = (string) ob_get_clean();

        return new ExportarPecaTextoOutput(
            conteudo: $conteudo,
            mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            nomeArquivo: $this->nomeSemExtensao($doc) . '.docx',
        );
    }

    private function gerarOdt(PastaDocumento $doc, string $html): ExportarPecaTextoOutput
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        Html::addHtml($section, $this->sanitizarParaXhtml($html), false, false);

        $writer = IOFactory::createWriter($phpWord, 'ODText');
        ob_start();
        $writer->save('php://output');
        $conteudo = (string) ob_get_clean();

        return new ExportarPecaTextoOutput(
            conteudo: $conteudo,
            mimeType: 'application/vnd.oasis.opendocument.text',
            nomeArquivo: $this->nomeSemExtensao($doc) . '.odt',
        );
    }

    private function gerarPdf(PastaDocumento $doc, string $html): ExportarPecaTextoOutput
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new ExportarPecaTextoOutput(
            conteudo: (string) $dompdf->output(),
            mimeType: 'application/pdf',
            nomeArquivo: $this->nomeSemExtensao($doc) . '.pdf',
        );
    }

    private function gerarTxt(PastaDocumento $doc, string $html): ExportarPecaTextoOutput
    {
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return new ExportarPecaTextoOutput(
            conteudo: $texto,
            mimeType: 'text/plain',
            nomeArquivo: $this->nomeSemExtensao($doc) . '.txt',
        );
    }

    private function nomeSemExtensao(PastaDocumento $doc): string
    {
        return pathinfo($doc->getTitulo(), PATHINFO_FILENAME) ?: $doc->getTitulo();
    }
}
