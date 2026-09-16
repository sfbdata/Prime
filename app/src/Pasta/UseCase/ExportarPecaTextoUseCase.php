<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\DTO\ExportarPecaTextoOutput;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use App\Pasta\Service\ReferenciasDePecaHtml;
use PhpOffice\PhpWord\Shared\Html;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exporta uma peça escrita no editor para DOCX, ODT, PDF ou TXT.
 *
 * ## Peça ausente ≠ storage indisponível (D13)
 *
 * O HTML é lido do armazenamento por chave. Sem arquivo na chave, `ler()` lança
 * {@see ArquivoNaoEncontrado} e o controller responde 404. Pane do storage (diretório ou arquivo
 * ilegível, backend fora) é `FalhaDeArmazenamento` e propaga como erro — nunca vira 404. Antes da
 * E2.4B, o `file_get_contents` devolvia false com um warning e o export saía VAZIO, com 200.
 *
 * As imagens embutidas (`reescreverImagensParaDisco`) e o `chroot` do Dompdf ainda leem do disco
 * pelo `projectDir`: são da E2.6, com o materializador.
 */
final class ExportarPecaTextoUseCase
{
    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly ReferenciasDePecaHtml $referencias,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    /**
     * @throws ArquivoNaoEncontrado quando o arquivo da peça não existe
     */
    public function executar(PastaDocumento $doc, string $formato): ExportarPecaTextoOutput
    {
        $html = $this->armazenamento->ler(ChavesDePasta::documento($doc));

        $htmlExport = $this->reescreverImagensParaDisco($html, $doc->getTenant()?->getId());

        return match ($formato) {
            'docx'  => $this->gerarDocx($doc, $htmlExport),
            'odt'   => $this->gerarOdt($doc, $htmlExport),
            'pdf'   => $this->gerarPdf($doc, $htmlExport),
            'txt'   => $this->gerarTxt($doc, $html),
            default => throw new \InvalidArgumentException("Formato não suportado: {$formato}"),
        };
    }

    /**
     * Reescreve as referências às imagens do editor embutidas no HTML da peça para caminho de disco,
     * para que Dompdf/PhpWord encontrem os arquivos no export.
     *
     * Isolamento por tenant (M5): as imagens do editor moram em `pastas/<tenantId>/`, então a
     * reescrita aponta o prefixo `.../uploads/pastas/` para a subpasta do tenant do doc — senão a
     * imagem embutida quebraria no DOCX/PDF exportado.
     *
     * O TinyMCE grava a URL como ABSOLUTA (`/uploads/pastas/<hex>`) ou RELATIVA
     * (`../../uploads/pastas/<hex>`, default `convert_urls`); o regex normaliza ambas, consumindo o
     * prefixo `./`/`../`/`/` inteiro — o `str_replace('/uploads/'...)` antigo deixava o `../..` para
     * trás e quebrava o caso relativo. `preg_replace_callback` (callback fixo) evita interpretação de
     * `$`/`\` do projectDir no valor de substituição. Guard: tenant null (caso degenerado / unit sem
     * DB) mantém o caminho sem subpasta.
     */
    private function reescreverImagensParaDisco(string $html, ?int $tenantId): string
    {
        $prefixoDisco = $this->projectDir . '/public/uploads/pastas/'
            . ($tenantId !== null ? $tenantId . '/' : '');

        // O padrão mora em `ReferenciasDePecaHtml` desde a E1: é o mesmo conhecimento que decide
        // quais arquivos uma peça referencia, e ter duas cópias dele era o caminho para uma
        // rotina de limpeza divergir do export.
        return $this->referencias->reescreverPrefixo($html, $prefixoDisco);
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
        // Sem o chroot o Dompdf bloqueia a leitura do arquivo de imagem local (segurança) e a imagem
        // da peça sai quebrada no PDF. Libera a leitura sob `public/` (web root; já público), onde a
        // reescrita `reescreverImagensParaDisco` aponta as imagens (`public/uploads/pastas/<tenant>/`).
        $options->set('chroot', $this->projectDir . '/public');

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
