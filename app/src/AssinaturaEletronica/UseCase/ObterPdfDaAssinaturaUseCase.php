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
use App\AssinaturaEletronica\Exception\MuitosPdfsGerados;
use App\AssinaturaEletronica\Exception\MuitosPdfsSendoGerados;
use App\AssinaturaEletronica\Exception\PdfDoDocumentoSendoGerado;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\PdfDaAssinatura;
use App\AssinaturaEletronica\Service\PdfFinalComSelo;
use App\AssinaturaEletronica\Service\Selo\CadeiaDoSeloPendente;
use App\AssinaturaEletronica\Service\Selo\FalhaNoSelo;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\Service\VagasDeGeracaoDoPdf;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ChaveDeArquivo;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Baixar PDF com selo" (A5, E3-4 e E3-5). O concluído sai do arquivo guardado, gerado e guardado agora se ainda faltar
 * (dev sem worker, fila atrasada); rascunho, aguardando, recusado e cancelado são gerados na hora, sem guardar.
 *
 * - Escritório: o documento do escritório logado, mesmo na Lixeira; de outro escritório, o mesmo 404 dos arquivos.
 * - Signatário (pelo token): só quem já assinou e enquanto o link vale, isto é, documento fora da Lixeira e concluído
 *   ou aguardando dentro do prazo. Fora disso, o mesmo "Link inválido." de qualquer token ruim.
 *
 * Gerar ocupa uma das vagas da web ({@see VagasDeGeracaoDoPdf}); sem vaga, {@see MuitosPdfsSendoGerados}. Com a vaga,
 * consome a cota do limitador de quem pede (`$cabeNaCota`, do controller); estourada, {@see MuitosPdfsGerados}. O
 * guardado não gera: não ocupa vaga nem gasta cota.
 *
 * O concluído sem PDF guardado trava o documento antes da vaga: se outro pedido da web já gera o PDF dele,
 * {@see PdfDoDocumentoSendoGerado}, sem ocupar vaga nem gastar cota. Quem gera renova a vaga e a trava do documento a
 * cada pedaço (o TTL não vence com o gs em subprocesso). O PDF é gerado inteiro e guardado antes de entregar (os bytes
 * entregues são os guardados); com o perfil leve e o selo, o primeiro clique pode dar 504 do nginx (120 s) já com 8 a
 * 16 páginas ({@see \App\AssinaturaEletronica\Service\PdfFinalComSelo}); o PHP termina e guarda, e o clique seguinte
 * entrega o guardado.
 *
 * Selo (etapa 8, E8-4): a web tenta selar uma vez, nunca como última tentativa. Não configurado ou falha definitiva:
 * guarda sem o selo, como o worker. A {@see FalhaNoSelo} que tenta de novo não guarda nada: marca a cadeia do selo do
 * documento ({@see CadeiaDoSeloPendente}), passa o pedido ao worker ({@see GerarPdfFinalDoDocumento}, que tenta mais
 * vezes) só se a marca é nova, e responde {@see PdfDoDocumentoSendoGerado}. Assim a web nunca deixa um documento sem o
 * selo por uma falha passageira. Com a cadeia marcada, o clique seguinte responde o mesmo antes da trava do documento,
 * da vaga e da cota: não gera, não pede o selo e não abre outra cadeia. A fila que falha ao receber o pedido solta a
 * marca nova e a falha sobe (o clique seguinte tenta de novo).
 */
final readonly class ObterPdfDaAssinaturaUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private SignatarioDoLink $link,
        private PdfDaAssinatura $pdf,
        private PdfFinalComSelo $pdfFinal,
        private ClockInterface $relogio,
        private VagasDeGeracaoDoPdf $vagas,
        private MessageBusInterface $mensagens,
        private CadeiaDoSeloPendente $cadeiaDoSelo,
    ) {
    }

    /**
     * @param \Closure(): bool $cabeNaCota consome a cota do limitador da geração; falso quando estourou
     *
     * @throws DocumentoNaoEncontrado
     * @throws PdfDoDocumentoSendoGerado
     * @throws MuitosPdfsSendoGerados
     * @throws MuitosPdfsGerados
     * @throws FalhaAoMontarPdf
     */
    public function doEscritorio(Tenant $tenant, int $id, \Closure $cabeNaCota): PdfParaEntregar
    {
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();

        return $this->entregar($documento, $cabeNaCota);
    }

    /**
     * @param \Closure(): bool $cabeNaCota consome a cota do limitador da geração; falso quando estourou
     *
     * @throws LinkInvalido
     * @throws PdfDoDocumentoSendoGerado
     * @throws MuitosPdfsSendoGerados
     * @throws MuitosPdfsGerados
     * @throws FalhaAoMontarPdf
     */
    public function doSignatario(#[\SensitiveParameter] string $token, \Closure $cabeNaCota): PdfParaEntregar
    {
        $signatario = $this->link->localizar($token);
        if (!$this->podeBaixar($signatario)) {
            throw new LinkInvalido();
        }

        return $this->entregar($signatario->getDocumento(), $cabeNaCota);
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

    /** @param \Closure(): bool $cabeNaCota */
    private function entregar(DocumentoParaAssinar $documento, \Closure $cabeNaCota): PdfParaEntregar
    {
        $nome = $this->pdf->nomeDoArquivo($documento);
        if ($documento->getSituacao() === SituacaoDoDocumento::Concluido) {
            $guardado = ChavesDeAssinaturaEletronica::pdfFinal($documento) !== null
                ? $this->pdfFinal->garantir($documento)
                : $this->gerarEGuardar($documento, $cabeNaCota);

            return PdfParaEntregar::guardado($guardado, $nome);
        }

        $vaga = $this->ocuparVaga($cabeNaCota);
        try {
            return PdfParaEntregar::naHora($this->pdf->pedacos($documento), $nome, $vaga);
        } catch (\Throwable $e) {
            $vaga->release();

            throw $e;
        }
    }

    /**
     * O concluído sem PDF guardado: a cadeia do selo pendente, a trava do documento, a vaga e a cota, nessa ordem; as
     * duas travas renovadas a cada pedaço e liberadas no fim. O selo que falha e tenta de novo passa o pedido ao worker,
     * uma vez por cadeia.
     *
     * @param \Closure(): bool $cabeNaCota
     *
     * @throws PdfDoDocumentoSendoGerado o worker tenta o selo de novo, outro pedido da web já gera, ou o selo acabou de
     *                                   falhar
     * @throws MuitosPdfsSendoGerados
     * @throws MuitosPdfsGerados
     */
    private function gerarEGuardar(DocumentoParaAssinar $documento, \Closure $cabeNaCota): ChaveDeArquivo
    {
        $id = (int) $documento->getId();
        if ($this->cadeiaDoSelo->estaPendente($id)) {
            throw new PdfDoDocumentoSendoGerado();
        }

        $doDocumento = $this->vagas->travarDocumento($id);
        try {
            $vaga = $this->ocuparVaga($cabeNaCota);
            try {
                return $this->pdfFinal->garantir($documento, static function () use ($vaga, $doDocumento): void {
                    VagasDeGeracaoDoPdf::renovar($vaga, $doDocumento);
                }, false);
            } catch (FalhaNoSelo) {
                $this->passarAoWorker($documento);

                throw new PdfDoDocumentoSendoGerado();
            } finally {
                $vaga->release();
            }
        } finally {
            $doDocumento->release();
        }
    }

    /**
     * O selo falhou e tenta de novo: o pedido vai ao worker uma vez por cadeia, só quando a marca é nova. A fila que
     * falha solta a marca nova (senão o download ficaria no 429 por até 30 min sem ninguém tentar o selo) e a falha
     * sobe.
     */
    private function passarAoWorker(DocumentoParaAssinar $documento): void
    {
        $id = (int) $documento->getId();
        $marca = $this->cadeiaDoSelo->marcar($id);
        if ($marca === null) {
            return;
        }

        try {
            $this->mensagens->dispatch(new GerarPdfFinalDoDocumento($id, (int) $documento->getTenant()->getId()));
        } catch (\Throwable $e) {
            $marca->release();

            throw $e;
        }
    }

    /**
     * A vaga primeiro, a cota depois: o pedido recusado por falta de vaga não gasta a cota.
     *
     * @param \Closure(): bool $cabeNaCota
     *
     * @throws MuitosPdfsSendoGerados
     * @throws MuitosPdfsGerados
     */
    private function ocuparVaga(\Closure $cabeNaCota): LockInterface
    {
        $vaga = $this->vagas->ocupar();
        if (!$cabeNaCota()) {
            $vaga->release();

            throw new MuitosPdfsGerados();
        }

        return $vaga;
    }
}
