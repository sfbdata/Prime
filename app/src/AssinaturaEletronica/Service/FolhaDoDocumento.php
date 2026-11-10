<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Pdf\EscritorioDaFolha;
use App\AssinaturaEletronica\Pdf\FolhaParaMontar;
use App\AssinaturaEletronica\Pdf\LayoutDaFolha;
use App\AssinaturaEletronica\Pdf\ModoDaFolha;
use App\AssinaturaEletronica\Pdf\PaginasDepoisDoConteudo;
use App\AssinaturaEletronica\Pdf\SemPaginasDepoisDoConteudo;
use App\AssinaturaEletronica\Pdf\SignatarioDaFolha;
use App\AssinaturaEletronica\Pdf\TextosDaFolha;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Tenant\Armazenamento\ChavesDeTenant;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A {@see FolhaParaMontar} de um documento (o `d` do `pdf(d)` do desenho, dc:392-394): blocos, layout, logo, cabeçalho
 * do escritório, signatários com a rubrica, o PDF enviado e o manifesto.
 *
 * - Rascunho: sem código (a folha imprime "RASCUNHO") e com o hash provisório do desenho, `sha(d.id + d.nome)`.
 * - Logo: a da BlueJus é a imagem do desenho; a de Meu Escritório e a própria vêm do armazenamento. Sem imagem, a
 *   folha imprime a marca "BlueJus" em texto.
 * - Cabeçalho: nome, endereço ("logradouro, número, complemento, bairro, Cidade/UF") e telefone de Meu Escritório.
 * - QR: URL absoluta da validação pública, montada pelo roteador (E3-14).
 * - Manifesto em toda folha, menos na folha branca sem código (E3-3, `!(d.limpa && !d.cod)`).
 * - Textos impressos: do bloco `pdf:` do catálogo da tela ({@see TextosDaFolha}), traduzidos a cada folha.
 */
final readonly class FolhaDoDocumento
{
    public const ROTA_DA_VALIDACAO = 'assinatura_eletronica_publica_validar_codigo';

    public function __construct(
        private ArmazenamentoDeArquivos $armazenamento,
        private LogoDaBluejusNoPdf $logoDaBluejus,
        private ManifestoDoDocumento $manifesto,
        private UrlGeneratorInterface $urls,
        private ClockInterface $relogio,
        private TranslatorInterface $tradutor,
    ) {
    }

    public function de(DocumentoParaAssinar $documento): FolhaParaMontar
    {
        $rascunho = $documento->getSituacao() === SituacaoDoDocumento::Rascunho;
        $textos = new TextosDaFolha($this->tradutor);

        return new FolhaParaMontar(
            modo: $rascunho ? ModoDaFolha::Rascunho : ModoDaFolha::Final,
            codigo: $documento->getCodigo(),
            hash: self::hash($documento),
            nome: $documento->getNome(),
            blocos: $documento->getBlocos(),
            layout: LayoutDaFolha::tryFrom($documento->getLayout()) ?? LayoutDaFolha::PaginaInteira,
            lateral: $documento->getLateral(),
            folhaBranca: $documento->isFolhaBranca(),
            logo: $this->logo($documento),
            escritorio: self::escritorio($documento->getTenant()),
            signatarios: array_map($this->signatario(...), $documento->getSignatarios()),
            concluido: $documento->getSituacao() === SituacaoDoDocumento::Concluido,
            emitidoEm: \DateTimeImmutable::createFromInterface($this->relogio->now()),
            urlDeValidacao: $this->urls->generate(self::ROTA_DA_VALIDACAO, ['codigo' => $documento->getCodigo() ?? $textos->rascunho], UrlGeneratorInterface::ABSOLUTE_URL),
            pdfEnviado: $this->lerOuFalhar(ChavesDeAssinaturaEletronica::arquivo($documento)),
            paginasDepois: $this->paginasDepois($documento),
            textos: $textos,
        );
    }

    /** O hash do envio; no rascunho, o provisório do desenho (`sha(d.id + d.nome)`). */
    private static function hash(DocumentoParaAssinar $documento): string
    {
        return strtolower($documento->getHashEnviado() ?? hash('sha256', $documento->getId() . $documento->getNome()));
    }

    private function paginasDepois(DocumentoParaAssinar $documento): PaginasDepoisDoConteudo
    {
        return $documento->isFolhaBranca() && $documento->getCodigo() === null
            ? new SemPaginasDepoisDoConteudo()
            : $this->manifesto->de($documento);
    }

    private function logo(DocumentoParaAssinar $documento): ?string
    {
        if ($documento->getLogoTipo() === TipoDeLogo::Bluejus) {
            return $this->logoDaBluejus->bytes();
        }
        $chave = $documento->getLogoTipo() === TipoDeLogo::Escritorio
            ? ChavesDeTenant::logo($documento->getTenant())
            : ChavesDeAssinaturaEletronica::logo($documento);

        try {
            return $chave === null ? null : $this->armazenamento->ler($chave);
        } catch (ArquivoNaoEncontrado) {
            return null; // logo que sumiu: a folha cai na marca em texto, como sem logo
        }
    }

    private function signatario(Signatario $signatario): SignatarioDaFolha
    {
        $digitado = trim((string) $signatario->getNomeDigitado());
        $assinou = $signatario->getSituacao() === SituacaoDoSignatario::Assinado;

        return new SignatarioDaFolha(
            nome: $digitado !== '' ? $digitado : $signatario->getNome(),
            assinadoEm: $assinou ? $signatario->getAssinadoEm() : null,
            rubrica: $assinou ? $this->lerOuFalhar(ChavesDeAssinaturaEletronica::rubrica($signatario)) : null,
        );
    }

    /** A rubrica e o PDF enviado fazem parte da prova: arquivo que falta é falha, nunca folha sem ele. */
    private function lerOuFalhar(?ChaveDeArquivo $chave): ?string
    {
        return $chave === null ? null : $this->armazenamento->ler($chave);
    }

    /** O cabeçalho do desenho (`C.esc`, `C.end`, `C.tel`) a partir de Meu Escritório; campo sem dado fica vazio. */
    private static function escritorio(Tenant $tenant): EscritorioDaFolha
    {
        $cidade = trim((string) $tenant->getCidade());
        $uf = trim((string) $tenant->getEstado());
        $partes = [$tenant->getLogradouro(), $tenant->getNumero(), $tenant->getComplemento(), $tenant->getBairro(), $cidade !== '' && $uf !== '' ? $cidade . '/' . $uf : $cidade];
        $preenchidas = array_filter(array_map(static fn (?string $p): string => trim((string) $p), $partes), static fn (string $p): bool => $p !== '');

        return new EscritorioDaFolha(
            nome: trim((string) $tenant->getName()),
            endereco: implode(', ', $preenchidas),
            telefone: trim((string) $tenant->getTelefone()),
        );
    }
}
