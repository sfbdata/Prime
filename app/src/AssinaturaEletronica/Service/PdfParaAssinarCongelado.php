<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use App\AssinaturaEletronica\Pdf\ModoDaFolha;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Psr\Clock\ClockInterface;

/**
 * O PDF para assinar congelado (etapa 5, bloco 5C; E5-1): o PDF do documento no modo assinar (`pdf(dA, 'assinar')`,
 * dc:466), gerado uma vez e guardado como a versão `para_assinar`. É o que o primeiro signatário gov.br ou ICP de
 * documento sem versão anterior assina fora do BlueJus, e o PDF devolvido tem de começar por ele (o prefixo da
 * {@see ConferenciaDoPdfAssinado}): por isso não é regerado a cada clique, como no desenho.
 *
 * Gera os pedaços num arquivo da área temporária, medindo o tamanho no caminho. Passou do teto ({@see TETO_EM_BYTES}: o
 * devolvido, com as assinaturas acrescentadas, tem de caber nos 20 MB do motor, E5-8), a geração para ali: a área sai
 * com o arquivo, nada é guardado e a recusa é {@see AcaoRecusada} com {@see GRANDE_DEMAIS}. Dentro do teto, o motor de
 * conferência lê os bytes (a análise da versão, como a do original do passo 1), e o arquivo é gravado pela fábrica
 * {@see ChavesDeAssinaturaEletronica::novaVersao()}. Diferente do download do {@see PdfDaAssinatura}, os bytes ficam
 * também na memória, até o teto: o motor só lê uma string, e com o {@see EntregaDoPdfDaAssinatura::limitar()} (256M)
 * os 19 MiB cabem.
 *
 * Com a linha do documento travada e relida ({@see SignatarioDoLink::travar()}): a vez do signatário
 * ({@see DocumentoParaAssinar::exigirVez()}) e, se outro pedido já congelou, a versão dele, e este apaga o próprio
 * arquivo depois do COMMIT pela {@see RemocaoAposTransacao}. Senão, guarda a versão e acrescenta à trilha
 * `pdf_para_assinar_gerado` com o sha256, no nome do signatário. Falha antes do COMMIT: a
 * {@see \App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo} apaga o arquivo novo.
 */
final readonly class PdfParaAssinarCongelado
{
    /** O teto do PDF para assinar, num ponto só: abaixo dos 20 MB do motor, para caber a assinatura de quem devolve. */
    public const TETO_EM_BYTES = 19 * 1024 * 1024;

    /** A recusa acima do teto, no catálogo público. */
    public const GRANDE_DEMAIS = 'assinatura_eletronica_publica.js.certificado.pdf_grande_demais';

    /** A finalidade da área temporária (`jusprime-<finalidade>-<uid>`). */
    public const AREA = 'assinaturaparaassinar';

    private const CONTEXTO = 'assinatura_eletronica_pdf_para_assinar';

    public function __construct(
        private PdfDaAssinatura $pdf,
        private ArmazenamentoDeArquivos $armazenamento,
        private DocumentoParaAssinarRepository $documentos,
        private VersaoDoPdfRepository $versoes,
        private SignatarioDoLink $link,
        private TrilhaDoDocumento $trilha,
        private ConferenciaDePdf $motor,
        private RemocaoAposTransacao $remocao,
        private ClockInterface $relogio,
        private int $teto = self::TETO_EM_BYTES,
    ) {
    }

    /**
     * @param (\Closure(): void)|null $aCadaPedaco chamado a cada pedaço gerado (para renovar as travas da geração)
     *
     * @return VersaoDoPdf a versão `para_assinar` guardada, a deste pedido ou a de quem congelou antes
     *
     * @throws AcaoRecusada     acima do teto ({@see GRANDE_DEMAIS}) ou, com a linha travada, fora da vez
     * @throws LinkInvalido     o documento sumiu entre a leitura e a trava
     * @throws FalhaAoMontarPdf
     */
    public function congelar(Signatario $signatario, ?\Closure $aCadaPedaco = null): VersaoDoPdf
    {
        $novo = $this->gerarEGravar($signatario->getDocumento(), $aCadaPedaco);
        $versao = $this->documentos->emTransacaoComArquivoNovo(
            fn (): VersaoDoPdf => $this->guardar($signatario, $novo),
            static fn (): array => [$novo['chave']],
            self::CONTEXTO,
        );
        if ($versao->getArquivoChave() !== $novo['chave']->nome) {
            $this->remocao->remover([$novo['chave']], self::CONTEXTO . ': outro pedido congelou o PDF para assinar antes');
        }

        return $versao;
    }

    /**
     * @param (\Closure(): void)|null $aCadaPedaco
     *
     * @return array{chave: ChaveDeArquivo, sha256: string, tamanho: int, analise: array<string, mixed>}
     *
     * @throws AcaoRecusada acima do teto, sem gravar nada
     */
    private function gerarEGravar(DocumentoParaAssinar $documento, ?\Closure $aCadaPedaco): array
    {
        $area = AreaTemporariaPrivada::criar(self::AREA);
        try {
            $bytes = '';
            [$caminho, $sha256] = PdfNoTemporario::gravar($area, $this->ateOTeto($this->pdf->pedacos($documento, ModoDaFolha::Assinar), $aCadaPedaco, $bytes));
            $analise = SerializacaoDaConferencia::paraJson($this->motor->conferir($bytes));
            $gravado = $this->armazenamento->gravar(ChavesDeAssinaturaEletronica::novaVersao($documento), FonteDeConteudo::deArquivoLocal($caminho));
        } finally {
            $area->liberar();
        }

        return ['chave' => $gravado->chave, 'sha256' => $sha256, 'tamanho' => $gravado->tamanhoBytes, 'analise' => $analise];
    }

    /**
     * Os mesmos pedaços, juntados em `$bytes` (para o motor, até o teto) e chamando `$aCadaPedaco` depois de gerar cada
     * um. O pedaço que passaria do teto para a geração com a recusa.
     *
     * @param \Generator<int, string> $pedacos
     * @param (\Closure(): void)|null $aCadaPedaco
     *
     * @return \Generator<int, string>
     *
     * @throws AcaoRecusada
     */
    private function ateOTeto(\Generator $pedacos, ?\Closure $aCadaPedaco, string &$bytes): \Generator
    {
        foreach ($pedacos as $pedaco) {
            if (\strlen($bytes) + \strlen($pedaco) > $this->teto) {
                throw new AcaoRecusada(self::GRANDE_DEMAIS);
            }
            $bytes .= $pedaco;
            if ($aCadaPedaco !== null) {
                $aCadaPedaco();
            }
            yield $pedaco;
        }
    }

    /**
     * Dentro da transação, com a linha travada e relida: a vez e, se outro pedido já congelou, a versão dele.
     *
     * @param array{chave: ChaveDeArquivo, sha256: string, tamanho: int, analise: array<string, mixed>} $novo
     *
     * @throws LinkInvalido
     * @throws AcaoRecusada
     */
    private function guardar(Signatario $signatario, array $novo): VersaoDoPdf
    {
        $this->link->travar($signatario);
        $documento = $signatario->getDocumento();
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $documento->exigirVez($signatario, $agora);
        $congelada = $this->versoes->paraAssinar($documento);
        if ($congelada !== null) {
            return $congelada;
        }

        $versao = VersaoDoPdf::paraAssinar(
            $documento,
            $this->versoes->proximaOrdem($documento),
            $novo['chave']->nome,
            $this->pdf->nomeDoArquivo($documento, ModoDaFolha::Assinar),
            $novo['sha256'],
            $novo['tamanho'],
            $novo['analise'],
            $agora,
        );
        $this->versoes->salvar($versao);
        $this->trilha->acrescentar($documento, TipoDeEvento::PdfParaAssinarGerado, ['sha256' => $novo['sha256']], null, $signatario);

        return $versao;
    }
}
