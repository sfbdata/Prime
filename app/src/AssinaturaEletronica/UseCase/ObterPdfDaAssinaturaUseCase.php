<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\PdfParaEntregar;
use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Exception\MuitosPdfsSendoGerados;
use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\PdfDaAssinatura;
use App\AssinaturaEletronica\Service\PdfFinalDoDocumento;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\Service\VagasDeGeracaoDoPdf;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * "Baixar PDF com selo" (A5, E3-4 e E3-5). O concluído sai do arquivo guardado, gerado e guardado agora se ainda faltar
 * (dev sem worker, fila atrasada); rascunho, aguardando, recusado e cancelado são gerados na hora, sem guardar.
 *
 * - Escritório: o documento do escritório logado, mesmo na Lixeira; de outro escritório, o mesmo 404 dos arquivos.
 * - Signatário (pelo token): só quem já assinou e enquanto o link vale, isto é, documento fora da Lixeira e concluído
 *   ou aguardando dentro do prazo. Fora disso, o mesmo "Link inválido." de qualquer token ruim.
 *
 * Gerar ocupa uma das vagas da web ({@see VagasDeGeracaoDoPdf}); sem vaga, {@see MuitosPdfsSendoGerados}. O guardado
 * não gera e não ocupa vaga. O concluído sem PDF guardado é gerado inteiro e guardado antes de entregar (os bytes
 * entregues são os guardados); acima de umas 100 páginas, o primeiro clique pode dar 504 do nginx (120 s), o PHP
 * termina e guarda, e o clique seguinte entrega o guardado.
 */
final readonly class ObterPdfDaAssinaturaUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private SignatarioDoLink $link,
        private PdfDaAssinatura $pdf,
        private PdfFinalDoDocumento $pdfFinal,
        private ClockInterface $relogio,
        private VagasDeGeracaoDoPdf $vagas,
    ) {
    }

    /**
     * @throws DocumentoNaoEncontrado
     * @throws MuitosPdfsSendoGerados
     * @throws FalhaAoMontarPdf
     */
    public function doEscritorio(Tenant $tenant, int $id): PdfParaEntregar
    {
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();

        return $this->entregar($documento);
    }

    /**
     * @throws LinkInvalido
     * @throws MuitosPdfsSendoGerados
     * @throws FalhaAoMontarPdf
     */
    public function doSignatario(#[\SensitiveParameter] string $token): PdfParaEntregar
    {
        $signatario = $this->link->localizar($token);
        if (!$this->podeBaixar($signatario)) {
            throw new LinkInvalido();
        }

        return $this->entregar($signatario->getDocumento());
    }

    private function podeBaixar(Signatario $signatario): bool
    {
        $documento = $signatario->getDocumento();
        if ($signatario->getSituacao() !== SituacaoDoSignatario::Assinado || $documento->estaNaLixeira()) {
            return false;
        }

        return $documento->getSituacao() === SituacaoDoDocumento::Concluido
            || ($documento->getSituacao() === SituacaoDoDocumento::Aguardando
                && !$documento->estaVencido(\DateTimeImmutable::createFromInterface($this->relogio->now())));
    }

    private function entregar(DocumentoParaAssinar $documento): PdfParaEntregar
    {
        $nome = $this->pdf->nomeDoArquivo($documento);
        $concluido = $documento->getSituacao() === SituacaoDoDocumento::Concluido;
        if ($concluido && ChavesDeAssinaturaEletronica::pdfFinal($documento) !== null) {
            return PdfParaEntregar::guardado($this->pdfFinal->garantir($documento), $nome);
        }

        $vaga = $this->vagas->ocupar();
        if ($concluido) {
            try {
                return PdfParaEntregar::guardado($this->pdfFinal->garantir($documento), $nome);
            } finally {
                $vaga->release();
            }
        }

        try {
            return PdfParaEntregar::naHora($this->pdf->pedacos($documento), $nome, $vaga);
        } catch (\Throwable $e) {
            $vaga->release();

            throw $e;
        }
    }
}
