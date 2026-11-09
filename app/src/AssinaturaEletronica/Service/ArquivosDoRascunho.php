<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\ConfiguracaoDaAssinatura;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\FonteDeUploadHttp;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Os arquivos do "Salvar rascunho": o PDF enviado, a logo própria do documento e a logo padrão do escritório. Roda
 * dentro da transação do UseCase; cada arquivo gravado entra na {@see TrocaDeArquivos} (sai se não confirmar) e cada
 * um que perdeu o uso também (sai depois do COMMIT).
 */
final readonly class ArquivosDoRascunho
{
    private const NOME_MAXIMO = 255;

    public function __construct(
        private ArmazenamentoDeArquivos $armazenamento,
        private CopiaDeArquivo $copia,
        private ContadorDePaginasDePdf $contador,
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

    public function aplicarLogo(DocumentoParaAssinar $documento, TipoDeLogo $tipo, ?UploadedFile $logo, ?ConfiguracaoDaAssinatura $configuracao, TrocaDeArquivos $troca): void
    {
        $atual = ChavesDeAssinaturaEletronica::logo($documento);

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
}
