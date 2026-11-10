<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use App\AssinaturaEletronica\Pdf\MontadorDoPdf;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O PDF com selo de um documento, em pedaços (início, uma página por pedaço, fim), e o nome do download. Nunca junta o
 * PDF numa string: quem recebe grava ou transmite pedaço a pedaço (revisão do 1a: 28 páginas juntas estouram 128M).
 */
final readonly class PdfDaAssinatura
{
    private const NOME_DO_ARQUIVO = 'assinatura_eletronica_tela.js.pdf.nome_arquivo';

    public function __construct(
        private MontadorDoPdf $montador,
        private FolhaDoDocumento $folha,
        private TranslatorInterface $tradutor,
    ) {
    }

    /**
     * Preguiçoso como o {@see MontadorDoPdf::emPedacos()}: a folha é lida agora, a diagramação no primeiro pedaço.
     *
     * @return \Generator<int, string>
     *
     * @throws FalhaAoMontarPdf
     */
    public function pedacos(DocumentoParaAssinar $documento): \Generator
    {
        return $this->montador->emPedacos($this->folha->de($documento));
    }

    /**
     * `'assinado_' + nome.normalize('NFD').replace(/[^\w ]/g, '').trim().replace(/\s+/g, '_').slice(0, 60) + '.pdf'`
     * (dc:425): sem acento nem sinal, espaços viram sublinhado, até 60 caracteres.
     */
    public function nomeDoArquivo(DocumentoParaAssinar $documento): string
    {
        $nome = (string) \Normalizer::normalize($documento->getNome(), \Normalizer::FORM_D);
        $nome = trim((string) preg_replace('/[^A-Za-z0-9_ ]/', '', $nome));
        $nome = substr((string) preg_replace('/\s+/', '_', $nome), 0, 60);

        return $this->tradutor->trans(self::NOME_DO_ARQUIVO, ['nome' => $nome]);
    }
}
