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
        $fonte = FonteDeUploadHttp::de($pdf);
        $chave = $fonte->gravarEm($this->armazenamento, ChavesDeAssinaturaEletronica::novoArquivo($documento, $fonte->extensao))->chave;
        $troca->gravada($chave);
        $troca->semUso(ChavesDeAssinaturaEletronica::arquivo($documento));

        $documento->definirArquivo($chave->nome, self::nomeDoPdf($pdf), $paginas);
    }

    /** O PDF enviado, conferido pelo motor sem gravar nada: nulo quando não traz assinatura digital. */
    public function conferirOriginal(UploadedFile $pdf): ?OriginalJaAssinado
    {
        $bytes = $pdf->getContent();

        return OriginalJaAssinado::de($bytes, $this->conferencia->conferir($bytes), self::nomeDoPdf($pdf));
    }

    /** O nome do PDF enviado, como o computador da pessoa o mandou; vazio vira "documento.pdf". */
    private static function nomeDoPdf(UploadedFile $pdf): string
    {
        $nome = mb_substr(trim($pdf->getClientOriginalName()), 0, self::NOME_MAXIMO);

        return $nome !== '' ? $nome : 'documento.pdf';
    }

    /**
     * Algum bloco é o parágrafo de evidência de um original (dc:374)? Sem página do PDF, é ele que diz que o conteúdo
     * ainda é o do original e não foi trocado.
     *
     * @param list<array<string, int|string>> $blocos já normalizados
     */
    public function temParagrafoDoOriginal(array $blocos): bool
    {
        return $this->semParagrafo($blocos)['lugar'] !== null;
    }

    /**
     * O parágrafo de dc:374 cabe no documento? Conferido antes de gravar qualquer arquivo, com o teto de blocos sem o
     * parágrafo; {@see comParagrafoDoOriginal()} confere de novo com o documento travado.
     *
     * @param list<array<string, int|string>> $blocos já normalizados
     *
     * @throws AcaoRecusada
     */
    public function exigirLugarParaOParagrafo(array $blocos, DocumentoParaAssinar $documento, bool $mudaOriginal, ?OriginalJaAssinado $novo): void
    {
        if ($this->paragrafoQueFica($documento, $mudaOriginal, $novo) !== null) {
            self::exigirLugar($this->semParagrafo($blocos)['blocos']);
        }
    }

    /**
     * Os blocos com o parágrafo de dc:374 do original que fica depois de salvar, e só ele: todo parágrafo de evidência
     * que veio da tela sai (o de um original anterior, de outra aba, ou o atual) e o do original que fica entra no lugar
     * do primeiro que saiu; sem nenhum, antes do primeiro lugar de assinatura (`k: a`) ou no fim, como o `salvarDoc` do
     * desenho. Roda com o documento travado e relido: o original guardado é o de agora, não o que a tela conhecia.
     *
     * @param list<array<string, int|string>> $blocos já normalizados
     *
     * @return list<array<string, int|string>>
     *
     * @throws AcaoRecusada
     */
    public function comParagrafoDoOriginal(array $blocos, DocumentoParaAssinar $documento, bool $mudaOriginal, ?OriginalJaAssinado $novo): array
    {
        ['blocos' => $blocos, 'lugar' => $lugar] = $this->semParagrafo($blocos);
        $paragrafo = $this->paragrafoQueFica($documento, $mudaOriginal, $novo);
        if ($paragrafo === null) {
            return $blocos;
        }

        self::exigirLugar($blocos);
        if ($lugar === null) {
            $assinatura = array_search('a', array_column($blocos, 'k'), true);
            $lugar = $assinatura === false ? \count($blocos) : $assinatura;
        }
        array_splice($blocos, $lugar, 0, [$this->blocoDoParagrafo($paragrafo)]);

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
            $novo->nome,
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
     * O texto do parágrafo do original que fica: o novo quando o original muda; senão, o guardado (refeito da análise).
     */
    private function paragrafoQueFica(DocumentoParaAssinar $documento, bool $mudaOriginal, ?OriginalJaAssinado $novo): ?string
    {
        if ($mudaOriginal) {
            return $novo !== null ? $this->apresentacao->paragrafo($novo->conferencia, $novo->sha256) : null;
        }
        $guardado = $documento->getId() !== null ? $this->versoes->original($documento) : null;

        return $guardado !== null
            ? $this->apresentacao->paragrafo(SerializacaoDaConferencia::deJson($guardado->getAnalise()), $guardado->getSha256())
            : null;
    }

    /**
     * Os blocos sem nenhum parágrafo de evidência e a posição do primeiro que saiu.
     *
     * @param list<array<string, int|string>> $blocos
     *
     * @return array{blocos: list<array<string, int|string>>, lugar: ?int}
     */
    private function semParagrafo(array $blocos): array
    {
        $padrao = $this->apresentacao->padraoDoParagrafo();
        $restantes = [];
        $lugar = null;
        foreach ($blocos as $bloco) {
            if ($bloco['k'] === 'p' && preg_match($padrao, (string) ($bloco['x'] ?? '')) === 1) {
                $lugar ??= \count($restantes);

                continue;
            }
            $restantes[] = $bloco;
        }

        return ['blocos' => $restantes, 'lugar' => $lugar];
    }

    /**
     * O teto de blocos vale com o parágrafo: sem lugar para ele, a mesma recusa do validador.
     *
     * @param list<array<string, int|string>> $blocos sem o parágrafo
     *
     * @throws AcaoRecusada
     */
    private static function exigirLugar(array $blocos): void
    {
        if (\count($blocos) >= ValidadorDeBlocos::BLOCOS_MAXIMO) {
            throw new AcaoRecusada('assinatura_eletronica.erro.blocos_demais');
        }
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
