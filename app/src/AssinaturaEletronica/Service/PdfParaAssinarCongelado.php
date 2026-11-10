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
use App\AssinaturaEletronica\Pdf\PerfilDoPdf;
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
 * Gera os pedaços num arquivo da área temporária, medindo o tamanho no caminho, primeiro no perfil do desenho
 * ({@see PerfilDoPdf::Desenho}). Passou do teto ({@see PdfDaAssinatura::TETO_PARA_O_MOTOR}, injetável: o devolvido, com
 * as assinaturas acrescentadas, tem de caber nos 20 MB do motor, E5-8), a geração para ali, a área sai com o arquivo e
 * gera de novo no perfil leve ({@see PerfilDoPdf::Leve}), noutra área. Passou de novo, nada é guardado e a recusa é
 * {@see AcaoRecusada} com {@see GRANDE_DEMAIS}. Dentro do teto, o motor de conferência lê os bytes (a análise da versão,
 * como a do original do passo 1), e o arquivo é gravado pela fábrica {@see ChavesDeAssinaturaEletronica::novaVersao()}:
 * nada vai para o armazenamento antes de caber. Diferente do download do {@see PdfDaAssinatura}, os bytes ficam também
 * na memória, até o teto: o motor só lê uma string, e com o {@see EntregaDoPdfDaAssinatura::limitar()} (256M) os 19 MiB
 * cabem. Os da primeira tentativa saem da memória antes da segunda.
 *
 * Com a linha do documento travada e relida ({@see SignatarioDoLink::travar()}): a vez do signatário
 * ({@see DocumentoParaAssinar::exigirVez()}; fora dela, {@see LinkInvalido}, como antes de gerar) e a última versão
 * guardada ({@see VersaoDoPdfRepository::ultima()}, a mesma regra de quem chama). Se já há uma (outro pedido congelou
 * ou outro signatário devolveu a dele enquanto este gerava), é ela que sai, e este apaga o próprio arquivo depois do
 * COMMIT pela {@see RemocaoAposTransacao}. Senão, guarda a versão e acrescenta à trilha `pdf_para_assinar_gerado` com o
 * sha256, no nome do signatário. Falha antes do COMMIT: a {@see \App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo}
 * apaga o arquivo novo.
 */
final readonly class PdfParaAssinarCongelado
{
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
        private int $teto = PdfDaAssinatura::TETO_PARA_O_MOTOR,
    ) {
    }

    /**
     * @param (\Closure(): void)|null $aCadaPedaco chamado a cada pedaço gerado (para renovar as travas da geração)
     *
     * @return VersaoDoPdf a última versão guardada: a `para_assinar` deste pedido ou a que outro guardou antes da trava
     *
     * @throws AcaoRecusada     nos dois perfis acima do teto ({@see GRANDE_DEMAIS})
     * @throws LinkInvalido     o documento sumiu entre a leitura e a trava ou, com a linha travada, fora da vez
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
            $this->remocao->remover([$novo['chave']], self::CONTEXTO . ': outra versão foi guardada antes da trava');
        }

        return $versao;
    }

    /**
     * No perfil do desenho; acima do teto, no leve; acima de novo, a recusa.
     *
     * @param (\Closure(): void)|null $aCadaPedaco
     *
     * @return array{chave: ChaveDeArquivo, sha256: string, tamanho: int, analise: array<string, mixed>}
     *
     * @throws AcaoRecusada nos dois perfis acima do teto, sem gravar nada
     */
    private function gerarEGravar(DocumentoParaAssinar $documento, ?\Closure $aCadaPedaco): array
    {
        return $this->gerarEGravarNo(PerfilDoPdf::Desenho, $documento, $aCadaPedaco)
            ?? $this->gerarEGravarNo(PerfilDoPdf::Leve, $documento, $aCadaPedaco)
            ?? throw new AcaoRecusada(self::GRANDE_DEMAIS);
    }

    /**
     * Uma tentativa num perfil, numa área temporária só dela, liberada no fim com o arquivo (o pela metade também).
     * Nulo acima do teto, sem conferir nem gravar.
     *
     * @param (\Closure(): void)|null $aCadaPedaco
     *
     * @return array{chave: ChaveDeArquivo, sha256: string, tamanho: int, analise: array<string, mixed>}|null
     */
    private function gerarEGravarNo(PerfilDoPdf $perfil, DocumentoParaAssinar $documento, ?\Closure $aCadaPedaco): ?array
    {
        $area = AreaTemporariaPrivada::criar(self::AREA);
        try {
            $bytes = '';
            $passou = false;
            [$caminho, $sha256] = PdfNoTemporario::gravar($area, $this->ateOTeto($this->pdf->pedacos($documento, ModoDaFolha::Assinar, $perfil), $aCadaPedaco, $bytes, $passou));
            if ($passou) {
                return null;
            }
            $analise = SerializacaoDaConferencia::paraJson($this->motor->conferir($bytes));
            $gravado = $this->armazenamento->gravar(ChavesDeAssinaturaEletronica::novaVersao($documento), FonteDeConteudo::deArquivoLocal($caminho));
        } finally {
            $area->liberar();
        }

        return ['chave' => $gravado->chave, 'sha256' => $sha256, 'tamanho' => $gravado->tamanhoBytes, 'analise' => $analise];
    }

    /**
     * Os mesmos pedaços, juntados em `$bytes` (para o motor, até o teto) e chamando `$aCadaPedaco` depois de gerar cada
     * um. O pedaço que passaria do teto para a geração ali, com `$passou` verdadeiro.
     *
     * @param \Generator<int, string> $pedacos
     * @param (\Closure(): void)|null $aCadaPedaco
     *
     * @return \Generator<int, string>
     */
    private function ateOTeto(\Generator $pedacos, ?\Closure $aCadaPedaco, string &$bytes, bool &$passou): \Generator
    {
        foreach ($pedacos as $pedaco) {
            if (\strlen($bytes) + \strlen($pedaco) > $this->teto) {
                $passou = true;

                return;
            }
            $bytes .= $pedaco;
            if ($aCadaPedaco !== null) {
                $aCadaPedaco();
            }
            yield $pedaco;
        }
    }

    /**
     * Dentro da transação, com a linha travada e relida: a vez (fora dela, o mesmo "Link inválido." de antes de gerar)
     * e a última versão guardada, pela mesma regra de quem chama ({@see VersaoDoPdfRepository::ultima()}).
     *
     * @param array{chave: ChaveDeArquivo, sha256: string, tamanho: int, analise: array<string, mixed>} $novo
     *
     * @throws LinkInvalido
     */
    private function guardar(Signatario $signatario, array $novo): VersaoDoPdf
    {
        $this->link->travar($signatario);
        $documento = $signatario->getDocumento();
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        try {
            $documento->exigirVez($signatario, $agora);
        } catch (AcaoRecusada) {
            throw new LinkInvalido();
        }
        $guardada = $this->versoes->ultima($documento);
        if ($guardada !== null) {
            return $guardada;
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
