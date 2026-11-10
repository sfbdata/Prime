<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Event\PdfFinalGuardado;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Pdf\PerfilDoPdf;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\Selo\FalhaNoSelo;
use App\AssinaturaEletronica\Service\Selo\ResultadoDoSelo;
use App\AssinaturaEletronica\Service\Selo\SeloDoPdfFinal;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Armazenamento\Sha256DeArquivo;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * O PDF final com selo do documento concluído (contrato da etapa 3, seção 2, item 7; E3-4): guardado uma vez, em
 * `arquivo_final_chave` e `hash_final`.
 *
 * Já guardado: devolve a chave. Senão, em passos: gera os pedaços num arquivo da área temporária (acima do
 * {@see PdfDaAssinatura::TETO_PARA_O_MOTOR}, gera de novo no perfil leve, metade dos pixels e JPEG 70), sela esse arquivo
 * ({@see SeloDoPdfFinal::aplicar()}, etapa 8, E8-4), mede o sha256 do que vai ao armazenamento (o selado, quando selou)
 * e grava. Depois, com a linha do documento travada e relida, leva o selo à trilha ({@see SeloDoPdfFinal::registrar()})
 * e guarda a chave e o sha256 ({@see DocumentoParaAssinar::guardarArquivoFinal()}), na mesma transação.
 *
 * Selo: o pedido ao validador roda fora da transação. A {@see FalhaNoSelo} que tenta de novo (fora da última
 * tentativa) sobe antes de gravar: nada no armazenamento nem na trilha, e a área temporária sai no `finally`. Não
 * configurado, falha definitiva ou última tentativa: guarda sem o selo, e o `logger->error` desse caso só sai depois do
 * COMMIT de quem guardou ({@see SeloDoPdfFinal::avisar()}).
 *
 * Falha antes do COMMIT: a {@see \App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo} apaga o arquivo novo. Dois
 * pedidos ao mesmo tempo (o worker e o primeiro download): o segundo encontra a chave do primeiro depois da trava, não
 * leva o próprio selo à trilha, apaga o próprio arquivo pela {@see RemocaoAposTransacao} (que registra no log o que não
 * sair) e devolve a chave guardada. Depois do COMMIT de quem guardou, {@see PdfFinalGuardado} é despachado (o vínculo à
 * pasta ouve); a falha de quem ouve vai para o log e não impede de devolver o PDF já guardado.
 *
 * Tempo: o PDF é gerado inteiro e guardado antes de entregar (os bytes entregues são os guardados: o hash final e o
 * selo assinam o arquivo inteiro). Todo PDF acima de 19 MiB no perfil do desenho (o que já acontece com 8 a 16
 * páginas) é gerado duas vezes, a segunda no perfil leve, mais o pedido do selo, de até 45 s: o primeiro clique pode
 * dar 504 do nginx (120 s); o PHP termina e guarda, e o clique seguinte entrega o guardado. Quem chama pela web passa
 * `$aCadaPedaco`, chamado a cada pedaço gerado, para renovar as travas da geração
 * ({@see VagasDeGeracaoDoPdf::renovar()}).
 */
final readonly class PdfFinalComSelo implements PdfFinalDoDocumento
{
    private const CONTEXTO = 'assinatura_eletronica_pdf_final';

    public function __construct(
        private PdfDaAssinatura $pdf,
        private SeloDoPdfFinal $selo,
        private ArmazenamentoDeArquivos $armazenamento,
        private DocumentoParaAssinarRepository $documentos,
        private RemocaoAposTransacao $remocao,
        private EventDispatcherInterface $eventos,
        private LoggerInterface $logger,
        private int $tetoParaOMotor = PdfDaAssinatura::TETO_PARA_O_MOTOR,
    ) {
    }

    /**
     * @param (\Closure(): void)|null $aCadaPedaco     chamado a cada pedaço gerado (só quando gera)
     * @param bool                    $ultimaTentativa a última tentativa do selo: a falha que tentaria de novo guarda sem o
     *                                                 selo
     *
     * @throws FalhaNoSelo o selo falhou e tenta de novo (fora da última tentativa); nada foi guardado
     */
    public function garantir(DocumentoParaAssinar $doc, ?\Closure $aCadaPedaco = null, bool $ultimaTentativa = false): ChaveDeArquivo
    {
        if ($doc->getSituacao() !== SituacaoDoDocumento::Concluido) {
            throw new \LogicException('Só o documento concluído tem PDF final com selo.');
        }
        $guardada = ChavesDeAssinaturaEletronica::pdfFinal($doc);
        if ($guardada !== null) {
            return $guardada;
        }

        [$nova, $sha256, $selo] = $this->gerarEGravar($doc, $aCadaPedaco, $ultimaTentativa);
        $guardou = $this->documentos->emTransacaoComArquivoNovo(
            fn (): bool => $this->guardar($doc, $nova, $sha256, $selo),
            static fn (): array => [$nova],
            self::CONTEXTO,
        );
        if (!$guardou) {
            $this->remocao->remover([$nova], self::CONTEXTO . ': outro pedido guardou o PDF final antes');

            return ChavesDeAssinaturaEletronica::pdfFinal($doc) ?? throw new \LogicException('PDF final guardado sem chave.');
        }

        $this->selo->avisar($doc, $selo);
        $this->avisarQueGuardou($doc);

        return $nova;
    }

    /** Depois do COMMIT: o PDF já está guardado, então a falha de quem ouve só vai para o log. */
    private function avisarQueGuardou(DocumentoParaAssinar $doc): void
    {
        try {
            $this->eventos->dispatch(new PdfFinalGuardado((int) $doc->getId()));
        } catch (\Throwable $e) {
            $this->logger->error('Falha de quem ouve o PDF final guardado do documento {documento}: {erro}', [
                'documento' => $doc->getId(),
                'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Gerar, selar, medir e gravar no armazenamento, nessa ordem, no mesmo arquivo da área temporária.
     *
     * @param (\Closure(): void)|null $aCadaPedaco
     *
     * @return array{ChaveDeArquivo, string, ResultadoDoSelo} a chave gravada, o sha256 do arquivo gravado e o que o selo
     *                                                        fez
     *
     * @throws FalhaNoSelo antes de gravar: nada vai ao armazenamento
     */
    private function gerarEGravar(DocumentoParaAssinar $doc, ?\Closure $aCadaPedaco, bool $ultimaTentativa): array
    {
        $area = AreaTemporariaPrivada::criar('assinaturapdf');
        try {
            $caminho = $this->gerar($area, $doc, $aCadaPedaco, PerfilDoPdf::Desenho);
            if (PdfNoTemporario::tamanho($area, $caminho) > $this->tetoParaOMotor) {
                // Acima do teto do motor e do validador: de novo no perfil leve, antes do selo (decisão de 10/11).
                $caminho = $this->gerar($area, $doc, $aCadaPedaco, PerfilDoPdf::Leve);
            }
            $selo = $this->selo->aplicar($doc, $area, $caminho, $ultimaTentativa);
            // O sha256 do arquivo que vai ao armazenamento: o selado, quando selou.
            $sha256 = Sha256DeArquivo::deArquivoLocal($caminho);
            $gravado = $this->armazenamento->gravar(ChavesDeAssinaturaEletronica::novoPdfFinal($doc), FonteDeConteudo::deArquivoLocal($caminho));
        } finally {
            $area->liberar();
        }

        return [$gravado->chave, $sha256, $selo];
    }

    /**
     * Os pedaços do PDF final, no perfil pedido, num arquivo novo da área temporária.
     *
     * @param (\Closure(): void)|null $aCadaPedaco
     *
     * @return string o caminho do arquivo
     */
    private function gerar(AreaTemporariaPrivada $area, DocumentoParaAssinar $doc, ?\Closure $aCadaPedaco, PerfilDoPdf $perfil): string
    {
        [$caminho] = PdfNoTemporario::gravar($area, self::avisando($this->pdf->pedacos($doc, $perfil), $aCadaPedaco));

        return $caminho;
    }

    /**
     * Os mesmos pedaços, chamando `$aCadaPedaco` depois de gerar cada um.
     *
     * @param \Generator<int, string> $pedacos
     * @param (\Closure(): void)|null $aCadaPedaco
     *
     * @return \Generator<int, string>
     */
    private static function avisando(\Generator $pedacos, ?\Closure $aCadaPedaco): \Generator
    {
        foreach ($pedacos as $pedaco) {
            if ($aCadaPedaco !== null) {
                $aCadaPedaco();
            }
            yield $pedaco;
        }
    }

    /**
     * Dentro da transação, com a linha travada e relida: falso quando outro pedido já guardou (e então o selo deste
     * pedido não vai à trilha).
     *
     * @throws DocumentoNaoEncontrado o documento sumiu do escritório entre a leitura e a trava
     */
    private function guardar(DocumentoParaAssinar $doc, ChaveDeArquivo $nova, string $sha256, ResultadoDoSelo $selo): bool
    {
        if (!$this->documentos->travarERecarregar($doc, $doc->getTenant())) {
            throw new DocumentoNaoEncontrado();
        }
        if ($doc->getArquivoFinalChave() !== null) {
            return false;
        }

        $this->selo->registrar($doc, $selo);
        $doc->guardarArquivoFinal($nova->nome, $sha256);

        return true;
    }
}
