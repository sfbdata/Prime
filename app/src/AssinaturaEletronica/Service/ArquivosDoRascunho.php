<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\OriginalJaAssinado;
use App\AssinaturaEletronica\Entity\ConfiguracaoDaAssinatura;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Http\FonteDeUploadHttp;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Os arquivos do "Salvar rascunho": o PDF enviado, a logo própria do documento e a logo padrão do escritório. Roda
 * dentro da transação do UseCase; cada arquivo gravado entra na {@see TrocaDeArquivos} (sai se não confirmar) e cada
 * um que perdeu o uso também (sai depois do COMMIT).
 *
 * Etapa 5, bloco 5A: o PDF enviado que já chega com assinatura digital é guardado também como versão `original`, num
 * arquivo próprio (nunca a chave do PDF do documento), com a conferência do motor; e o conteúdo ganha o parágrafo de
 * dc:374 antes do primeiro lugar de assinatura.
 */
final readonly class ArquivosDoRascunho
{
    private const NOME_MAXIMO = 255;

    public function __construct(
        private ArmazenamentoDeArquivos $armazenamento,
        private CopiaDeArquivo $copia,
        private ContadorDePaginasDePdf $contador,
        private VersaoDoPdfRepository $versoes,
        private ConferenciaDePdf $conferencia,
        private ApresentacaoDaConferencia $apresentacao,
    ) {
    }

    /** Páginas do PDF em trânsito; nulo quando o contador não lê (o bloco de página aceita até 2.000). */
    public function paginas(UploadedFile $pdf): ?int
    {
        return $this->contador->contar($pdf->getPathname());
    }

    public function gravarPdf(DocumentoParaAssinar $documento, UploadedFile $pdf, ?int $paginas, TrocaDeArquivos $troca): void
    {
        $nome = mb_substr(trim($pdf->getClientOriginalName()), 0, self::NOME_MAXIMO);
        $fonte = FonteDeUploadHttp::de($pdf);
        $chave = $fonte->gravarEm($this->armazenamento, ChavesDeAssinaturaEletronica::novoArquivo($documento, $fonte->extensao))->chave;
        $troca->gravada($chave);
        $troca->semUso(ChavesDeAssinaturaEletronica::arquivo($documento));

        $documento->definirArquivo($chave->nome, $nome !== '' ? $nome : 'documento.pdf', $paginas);
    }

    /** O PDF enviado, conferido pelo motor sem gravar nada: nulo quando não traz assinatura digital. */
    public function conferirOriginal(UploadedFile $pdf): ?OriginalJaAssinado
    {
        $bytes = $pdf->getContent();

        return OriginalJaAssinado::de($bytes, $this->conferencia->conferir($bytes));
    }

    /** O original guardado no rascunho; nulo no documento ainda sem id. */
    public function originalGuardado(DocumentoParaAssinar $documento): ?VersaoDoPdf
    {
        return $documento->getId() !== null ? $this->versoes->original($documento) : null;
    }

    /**
     * Os blocos quando o original muda: sai o parágrafo do original anterior (o texto dele é refeito da análise
     * guardada) e entra o do novo antes do primeiro lugar de assinatura (`k: a`) ou no fim, como o `salvarDoc` do
     * desenho (dc:374).
     *
     * @param list<array<string, int|string>> $blocos já normalizados
     *
     * @return list<array<string, int|string>>
     *
     * @throws AcaoRecusada
     */
    public function comParagrafoDoOriginal(array $blocos, ?VersaoDoPdf $anterior, ?OriginalJaAssinado $novo): array
    {
        if ($anterior !== null) {
            $antigo = $this->blocoDoParagrafo($this->apresentacao->paragrafo(SerializacaoDaConferencia::deJson($anterior->getAnalise()), $anterior->getSha256()));
            $blocos = array_values(array_filter($blocos, static fn (array $b): bool => $b !== $antigo));
        }
        if ($novo === null) {
            return $blocos;
        }

        $lugar = array_search('a', array_column($blocos, 'k'), true);
        array_splice($blocos, $lugar === false ? \count($blocos) : $lugar, 0, [$this->blocoDoParagrafo($this->apresentacao->paragrafo($novo->conferencia, $novo->sha256))]);

        return $blocos;
    }

    /**
     * Troca o original guardado: o anterior sai (o registro já, o arquivo depois do COMMIT) e o novo, se houver, ganha
     * arquivo próprio. Roda com o documento travado e já salvo (tem id); o `remover` do repositório dá flush na hora,
     * antes do `persist` do novo, por causa do índice único parcial do original.
     */
    public function trocarOriginal(DocumentoParaAssinar $documento, ?OriginalJaAssinado $novo, TrocaDeArquivos $troca, \DateTimeImmutable $agora): void
    {
        $anterior = $this->versoes->original($documento);
        if ($anterior !== null) {
            $troca->semUso(ChavesDeAssinaturaEletronica::versao($anterior));
            $this->versoes->remover($anterior);
        }
        if ($novo === null) {
            return;
        }

        $chave = $this->armazenamento->gravar(ChavesDeAssinaturaEletronica::novaVersao($documento), FonteDeConteudo::deTexto($novo->bytes))->chave;
        $troca->gravada($chave);
        $this->versoes->salvar(VersaoDoPdf::original(
            $documento,
            $this->versoes->proximaOrdem($documento),
            $chave->nome,
            (string) $documento->getArquivoEnviadoNome(),
            $novo->sha256,
            \strlen($novo->bytes),
            SerializacaoDaConferencia::paraJson($novo->conferencia),
            $agora,
        ));
    }

    /** Rascunho sem página do PDF (bloco `pg`) não guarda o PDF: o atual perde o uso e sai depois do COMMIT. */
    public function descartarPdf(DocumentoParaAssinar $documento, TrocaDeArquivos $troca): void
    {
        $troca->semUso(ChavesDeAssinaturaEletronica::arquivo($documento));
        $documento->descartarArquivo();
    }

    /**
     * Logo própria sem imagem enviada só vale se já houver uma: a do documento ou a padrão do escritório (copiada).
     */
    public function temOrigemDaLogo(DocumentoParaAssinar $documento, TipoDeLogo $tipo, ?UploadedFile $logo, ?ConfiguracaoDaAssinatura $configuracao): bool
    {
        return $tipo !== TipoDeLogo::Propria
            || $logo !== null
            || $documento->getLogoChave() !== null
            || ($configuracao !== null && ChavesDeAssinaturaEletronica::logoPadrao($configuracao) !== null);
    }

    /**
     * A logo do documento. "Meu Escritório" sem logo cadastrada em Meu Escritório vira a da BlueJus: o documento nunca
     * fica apontando para uma imagem que não existe.
     */
    public function aplicarLogo(DocumentoParaAssinar $documento, TipoDeLogo $tipo, ?UploadedFile $logo, ?ConfiguracaoDaAssinatura $configuracao, TrocaDeArquivos $troca): void
    {
        $atual = ChavesDeAssinaturaEletronica::logo($documento);
        if ($tipo === TipoDeLogo::Escritorio && !self::escritorioTemLogo($documento)) {
            $tipo = TipoDeLogo::Bluejus;
        }

        if ($tipo !== TipoDeLogo::Propria) {
            $troca->semUso($atual);
            $documento->definirLogo($tipo, null);

            return;
        }

        if ($logo !== null) {
            $fonte = FonteDeUploadHttp::de($logo);
            $chave = $fonte->gravarEm($this->armazenamento, ChavesDeAssinaturaEletronica::novaLogo($documento, $fonte->extensao))->chave;
            $troca->gravada($chave);
            $troca->semUso($atual);
            $documento->definirLogo($tipo, $chave->nome);

            return;
        }

        if ($atual !== null) {
            return;
        }

        $padrao = $configuracao !== null ? ChavesDeAssinaturaEletronica::logoPadrao($configuracao) : null;
        if ($padrao === null) {
            throw new \LogicException('Logo própria sem origem: confira antes com temOrigemDaLogo().');
        }

        $chave = $this->copia->copiar($padrao, static fn (string $extensao) => ChavesDeAssinaturaEletronica::novaLogo($documento, $extensao));
        $troca->gravada($chave);
        $documento->definirLogo($tipo, $chave->nome);
    }

    /** "Usar esta logo nos próximos documentos": a logo do documento vira a padrão do escritório (como cópia). */
    public function tornarPadrao(DocumentoParaAssinar $documento, ConfiguracaoDaAssinatura $configuracao, TrocaDeArquivos $troca): void
    {
        $troca->semUso(ChavesDeAssinaturaEletronica::logoPadrao($configuracao));
        $logo = ChavesDeAssinaturaEletronica::logo($documento);

        if ($documento->getLogoTipo() !== TipoDeLogo::Propria || $logo === null) {
            $configuracao->definirLogoPadrao($documento->getLogoTipo(), null);

            return;
        }

        $chave = $this->copia->copiar($logo, static fn (string $extensao) => ChavesDeAssinaturaEletronica::novaLogoPadrao($configuracao, $extensao));
        $troca->gravada($chave);
        $configuracao->definirLogoPadrao(TipoDeLogo::Propria, $chave->nome);
    }

    /**
     * "Usar esta logo nos próximos documentos" desmarcada: os próximos documentos voltam a nascer com a logo da BlueJus
     * (o `tgLogoFix` do desenho). A imagem da logo própria padrão é sempre uma cópia só dela ({@see tornarPadrao()}):
     * perde o uso e sai depois do COMMIT.
     */
    public function limparPadrao(ConfiguracaoDaAssinatura $configuracao, TrocaDeArquivos $troca): void
    {
        $troca->semUso(ChavesDeAssinaturaEletronica::logoPadrao($configuracao));
        $configuracao->definirLogoPadrao(TipoDeLogo::Bluejus, null);
    }

    /**
     * O parágrafo na forma normalizada de um bloco de texto, a mesma de quando volta da tela.
     *
     * @return array<string, int|string>
     *
     * @throws AcaoRecusada
     */
    private function blocoDoParagrafo(string $texto): array
    {
        return ValidadorDeBlocos::normalizar([['k' => 'p', 'x' => $texto]], false, null)[0];
    }

    private static function escritorioTemLogo(DocumentoParaAssinar $documento): bool
    {
        $chave = $documento->getTenant()->getLogoChave();

        return $chave !== null && $chave !== '';
    }
}
