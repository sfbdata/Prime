<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\PdfParaEntregar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\EstadoDaPagina;
use App\AssinaturaEletronica\Enum\OrigemDaVersao;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Exception\MuitosPdfsGerados;
use App\AssinaturaEletronica\Exception\MuitosPdfsSendoGerados;
use App\AssinaturaEletronica\Exception\PdfDoDocumentoSendoGerado;
use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use App\AssinaturaEletronica\Pdf\ModoDaFolha;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\PdfDaAssinatura;
use App\AssinaturaEletronica\Service\PdfParaAssinarCongelado;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\Service\VagasDeGeracaoDoPdf;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Baixar PDF para assinar" da tela pública (etapa 5, bloco 5C; `baixarPara`, dc:466): o PDF que o signatário gov.br
 * ou ICP assina fora do BlueJus e devolve pela pré-conferência e pela assinatura com certificado.
 *
 * Só para quem assina com certificado e na vez dele, como a pré-conferência (documento aguardando, no prazo, signatário
 * pendente e, com ordem, a vez); fora disso, o mesmo "Link inválido." de qualquer token ruim.
 *
 * - Com versão guardada ({@see VersaoDoPdfRepository::ultima()}: o original já assinado, o que outro signatário
 *   devolveu ou o PDF para assinar já congelado), entrega essa versão sem gerar nada: não trava, não ocupa vaga nem
 *   gasta cota. O nome é "{nome} - para assinar.pdf" (`presOrig(dA, ' - para assinar')`, dc:383 e 466); o PDF para
 *   assinar congelado continua com o nome de quando foi gerado, `para_assinar_` (dc:425).
 * - Sem versão, gera o PDF do documento no modo assinar e o congela ({@see PdfParaAssinarCongelado}), na ordem do
 *   concluído sem PDF guardado do {@see ObterPdfDaAssinaturaUseCase}: a trava do documento
 *   ({@see PdfDoDocumentoSendoGerado}), a vaga ({@see MuitosPdfsSendoGerados}) e a cota de quem pede
 *   ({@see MuitosPdfsGerados}), as duas travas renovadas a cada pedaço e liberadas no fim. Da segunda vez em diante,
 *   a versão congelada é a última, e sai pelo caminho de cima.
 *
 * O congelamento tenta o perfil do desenho e, acima do teto ({@see PdfDaAssinatura::TETO_PARA_O_MOTOR}), o leve. Os
 * dois acima do teto: {@see AcaoRecusada} com o texto do catálogo público, sem guardar nada. Como no download do PDF
 * com selo ({@see ObterPdfDaAssinaturaUseCase::doSignatario()}), os estados do documento e do signatário saem todos
 * como {@see LinkInvalido}: também a vez que muda entre a conferência e a trava da linha (o documento
 * cancelado nesse meio), e o arquivo gerado sai do disco. Se, nesse meio, outro signatário devolveu a versão dele, é
 * ela que sai (a última, a mesma regra de cima), como "{nome} - para assinar.pdf", e o arquivo gerado sai do disco.
 */
final readonly class ObterPdfParaAssinarUseCase
{
    private const NOME_DA_VERSAO = 'assinatura_eletronica_publica.js.certificado.nome_para_assinar';

    public function __construct(
        private SignatarioDoLink $link,
        private VersaoDoPdfRepository $versoes,
        private PdfParaAssinarCongelado $congelado,
        private PdfDaAssinatura $pdf,
        private VagasDeGeracaoDoPdf $vagas,
        private TranslatorInterface $tradutor,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @param \Closure(): bool $cabeNaCota consome a cota do limitador da geração; falso quando estourou
     *
     * @throws LinkInvalido              token ruim, sem certificado ou fora da vez, antes de gerar ou com a linha travada
     * @throws PdfDoDocumentoSendoGerado
     * @throws MuitosPdfsSendoGerados
     * @throws MuitosPdfsGerados
     * @throws AcaoRecusada              só o PDF acima do teto nos dois perfis
     * @throws FalhaAoMontarPdf
     */
    public function executar(#[\SensitiveParameter] string $token, \Closure $cabeNaCota): PdfParaEntregar
    {
        $signatario = $this->link->localizar($token);
        $this->exigirVezComCertificado($signatario);
        $versao = $this->versoes->ultima($signatario->getDocumento()) ?? $this->congelar($signatario, $cabeNaCota);

        return PdfParaEntregar::guardado(ChavesDeAssinaturaEletronica::versao($versao), $this->nome($versao));
    }

    /** @throws LinkInvalido Simples ou Reforçada, ou fora da vez */
    private function exigirVezComCertificado(Signatario $signatario): void
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        if (!$signatario->assinaComCertificado() || $signatario->getDocumento()->estadoPara($signatario, $agora) !== EstadoDaPagina::Pronto) {
            throw new LinkInvalido();
        }
    }

    /**
     * A trava do documento, a vaga e a cota, nessa ordem; as duas travas renovadas a cada pedaço e liberadas no fim.
     *
     * @param \Closure(): bool $cabeNaCota
     */
    private function congelar(Signatario $signatario, \Closure $cabeNaCota): VersaoDoPdf
    {
        $doDocumento = $this->vagas->travarDocumento((int) $signatario->getDocumento()->getId());
        try {
            $vaga = $this->ocuparVaga($cabeNaCota);
            try {
                return $this->congelado->congelar($signatario, static function () use ($vaga, $doDocumento): void {
                    VagasDeGeracaoDoPdf::renovar($vaga, $doDocumento);
                });
            } finally {
                $vaga->release();
            }
        } finally {
            $doDocumento->release();
        }
    }

    /**
     * A vaga primeiro, a cota depois: o pedido recusado por falta de vaga não gasta a cota.
     *
     * @param \Closure(): bool $cabeNaCota
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

    /** O congelado com o nome do PDF gerado (dc:425); o original e o devolvido com o " - para assinar" (dc:383). */
    private function nome(VersaoDoPdf $versao): string
    {
        $documento = $versao->getDocumento();

        return $versao->getOrigem() === OrigemDaVersao::ParaAssinar
            ? $this->pdf->nomeDoArquivo($documento, ModoDaFolha::Assinar)
            : $this->tradutor->trans(self::NOME_DA_VERSAO, ['nome' => $documento->getNome()]);
    }
}
