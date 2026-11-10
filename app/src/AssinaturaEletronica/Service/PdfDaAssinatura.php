<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use App\AssinaturaEletronica\Pdf\ModoDaFolha;
use App\AssinaturaEletronica\Pdf\MontadorDoPdf;
use App\AssinaturaEletronica\Pdf\PerfilDoPdf;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O PDF com selo de um documento, em pedaços (início, uma página por pedaço, fim), e o nome do download. Nunca junta o
 * PDF numa string: quem recebe grava ou transmite pedaço a pedaço (revisão do 1a: 28 páginas juntas estouram 128M).
 *
 * Etapa 5, bloco 5C: o modo da folha é opcional ({@see FolhaDoDocumento::de()}); o PDF para assinar
 * (`pdf(dA, 'assinar')`, dc:466) é a mesma folha com o nome `para_assinar_` (dc:425).
 */
final readonly class PdfDaAssinatura
{
    /** O teto de 20 MB do motor e do validador (E5-8 e etapa 6), menos 1 MiB de folga para a assinatura. */
    public const TETO_PARA_O_MOTOR = 19 * 1024 * 1024;

    private const NOME_DO_ARQUIVO = 'assinatura_eletronica_tela.js.pdf.nome_arquivo';
    private const NOME_DO_ARQUIVO_PARA_ASSINAR = 'assinatura_eletronica_tela.js.pdf.nome_arquivo_para_assinar';

    public function __construct(
        private MontadorDoPdf $montador,
        private FolhaDoDocumento $folha,
        private TranslatorInterface $tradutor,
    ) {
    }

    /**
     * Preguiçoso como o {@see MontadorDoPdf::emPedacos()}: a folha é lida agora, a diagramação no primeiro pedaço.
     * Sem modo, a regra de {@see FolhaDoDocumento::de()} (rascunho ou final). O perfil padrão é o do desenho; o leve
     * fica para o gerado que passar do {@see TETO_PARA_O_MOTOR}.
     *
     * @return \Generator<int, string>
     *
     * @throws FalhaAoMontarPdf
     */
    public function pedacos(
        DocumentoParaAssinar $documento,
        ?ModoDaFolha $modo = null,
        PerfilDoPdf $perfil = PerfilDoPdf::Desenho,
    ): \Generator {
        return $this->montador->emPedacos($this->folha->de($documento, $modo), $perfil);
    }

    /**
     * `(modo === 'assinar' ? 'para_assinar_' : 'assinado_') + nome.normalize('NFD').replace(/[^\w ]/g, '').trim()
     * .replace(/\s+/g, '_').slice(0, 60) + '.pdf'` (dc:425): sem acento nem sinal, espaços viram sublinhado, até 60
     * caracteres. Só o modo assinar muda o prefixo; o rascunho sai como o final, `assinado_`.
     */
    public function nomeDoArquivo(DocumentoParaAssinar $documento, ModoDaFolha $modo = ModoDaFolha::Final): string
    {
        $nome = (string) \Normalizer::normalize($documento->getNome(), \Normalizer::FORM_D);
        $nome = trim((string) preg_replace('/[^A-Za-z0-9_ ]/', '', $nome));
        $nome = substr((string) preg_replace('/\s+/', '_', $nome), 0, 60);

        return $this->tradutor->trans($modo === ModoDaFolha::Assinar ? self::NOME_DO_ARQUIVO_PARA_ASSINAR : self::NOME_DO_ARQUIVO, ['nome' => $nome]);
    }
}
