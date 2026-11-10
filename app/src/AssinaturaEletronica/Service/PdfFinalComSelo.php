<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Event\PdfFinalGuardado;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * O PDF final com selo do documento concluído (contrato da etapa 3, seção 2, item 7; E3-4): guardado uma vez, em
 * `arquivo_final_chave` e `hash_final`.
 *
 * Já guardado: devolve a chave. Senão gera os pedaços num arquivo da área temporária, grava no armazenamento e, com a
 * linha do documento travada e relida, guarda a chave e o sha256 ({@see DocumentoParaAssinar::guardarArquivoFinal()}).
 * Falha antes do COMMIT: a {@see \App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo} apaga o arquivo novo. Dois
 * pedidos ao mesmo tempo (o worker e o primeiro download): o segundo encontra a chave do primeiro depois da trava,
 * apaga o próprio arquivo pela {@see RemocaoAposTransacao} (que registra no log o que não sair) e devolve a chave
 * guardada. Depois do COMMIT de quem guardou, {@see PdfFinalGuardado} é despachado (o vínculo à pasta ouve).
 */
final readonly class PdfFinalComSelo implements PdfFinalDoDocumento
{
    private const CONTEXTO = 'assinatura_eletronica_pdf_final';

    public function __construct(
        private PdfDaAssinatura $pdf,
        private ArmazenamentoDeArquivos $armazenamento,
        private DocumentoParaAssinarRepository $documentos,
        private RemocaoAposTransacao $remocao,
        private EventDispatcherInterface $eventos,
    ) {
    }

    public function garantir(DocumentoParaAssinar $doc): ChaveDeArquivo
    {
        if ($doc->getSituacao() !== SituacaoDoDocumento::Concluido) {
            throw new \LogicException('Só o documento concluído tem PDF final com selo.');
        }
        $guardada = ChavesDeAssinaturaEletronica::pdfFinal($doc);
        if ($guardada !== null) {
            return $guardada;
        }

        [$nova, $sha256] = $this->gerarEGravar($doc);
        $guardou = $this->documentos->emTransacaoComArquivoNovo(
            fn (): bool => $this->guardar($doc, $nova, $sha256),
            static fn (): array => [$nova],
            self::CONTEXTO,
        );
        if (!$guardou) {
            $this->remocao->remover([$nova], self::CONTEXTO . ': outro pedido guardou o PDF final antes');

            return ChavesDeAssinaturaEletronica::pdfFinal($doc) ?? throw new \LogicException('PDF final guardado sem chave.');
        }

        $this->eventos->dispatch(new PdfFinalGuardado((int) $doc->getId()));

        return $nova;
    }

    /** @return array{ChaveDeArquivo, string} a chave gravada e o sha256 do arquivo */
    private function gerarEGravar(DocumentoParaAssinar $doc): array
    {
        $area = AreaTemporariaPrivada::criar('assinaturapdf');
        try {
            [$caminho, $sha256] = PdfNoTemporario::gravar($area, $this->pdf->pedacos($doc));
            $gravado = $this->armazenamento->gravar(ChavesDeAssinaturaEletronica::novoPdfFinal($doc), FonteDeConteudo::deArquivoLocal($caminho));
        } finally {
            $area->liberar();
        }

        return [$gravado->chave, $sha256];
    }

    /**
     * Dentro da transação, com a linha travada e relida: falso quando outro pedido já guardou.
     *
     * @throws DocumentoNaoEncontrado o documento sumiu do escritório entre a leitura e a trava
     */
    private function guardar(DocumentoParaAssinar $doc, ChaveDeArquivo $nova, string $sha256): bool
    {
        if (!$this->documentos->travarERecarregar($doc, $doc->getTenant())) {
            throw new DocumentoNaoEncontrado();
        }
        if ($doc->getArquivoFinalChave() !== null) {
            return false;
        }

        $doc->guardarArquivoFinal($nova->nome, $sha256);

        return true;
    }
}
