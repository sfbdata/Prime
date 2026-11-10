<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\OriginalJaAssinado;
use App\AssinaturaEletronica\Service\ApresentacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * O quadro "Documento já assinado" (presV do desenho, dc:381) e o parágrafo de dc:374, com o motor de verdade e o
 * catálogo pt_BR de verdade: os textos são os do inventário 5A.
 */
#[CoversClass(ApresentacaoDaConferencia::class)]
#[CoversClass(OriginalJaAssinado::class)]
final class ApresentacaoDaConferenciaTest extends TestCase
{
    private const NOTA = 'O arquivo original fica guardado sem alteração, e novas assinaturas digitais entram ao final dele, sem apagar as anteriores. Aqui se confere a integridade; a validade do certificado e da cadeia ICP-Brasil é confirmada no Validar do ITI.';
    private const NOTA_DOCMDP = ' O primeiro signatário bloqueou qualquer alteração: nova assinatura digital invalidaria o documento.';
    private const AVISO_CARIMBO = 'Para validar as assinaturas, use o original. O carimbo é um acréscimo visual: as assinaturas continuam íntegras, mas os validadores indicam alteração posterior.';
    private const AVISO_BLOQUEADO = 'O primeiro signatário bloqueou alterações neste PDF: use o original e o manifesto com selo.';
    private const EMISSOR = 'Emissor: ' . OficinaDePdfAssinado::TITULAR . ':' . OficinaDePdfAssinado::CPF;

    private ConferenciaDePdf $motor;
    private ApresentacaoDaConferencia $apresentacao;

    protected function setUp(): void
    {
        $tradutor = new Translator('pt_BR');
        $tradutor->addLoader('yaml', new YamlFileLoader());
        CatalogoPtBr::registrarEm($tradutor);
        $this->motor = new ConferenciaDePdf();
        $this->apresentacao = new ApresentacaoDaConferencia($tradutor);
    }

    #[TestDox('PDF sem assinatura digital: sem quadro e sem original (não é "já assinado")')]
    public function testSemAssinatura(): void
    {
        $pdf = OficinaDePdfAssinado::pdfSemAssinatura();
        $resultado = $this->motor->conferir($pdf);

        self::assertNull($this->apresentacao->quadro($resultado));
        self::assertNull(OriginalJaAssinado::de($pdf, $resultado));
    }

    #[TestDox('uma assinatura íntegra: título, verde, linha com nome, documento, data em ISO e o detalhe do desenho; aceita carimbo')]
    public function testUmaIntegra(): void
    {
        $quadro = $this->apresentacao->quadro($this->motor->conferir(OficinaDePdfAssinado::pdfAssinado()));

        self::assertNotNull($quadro);
        self::assertSame('Documento já assinado: 1 assinatura digital reconhecida', $quadro['titulo']);
        self::assertSame('#1e7b45', $quadro['cor']);
        self::assertCount(1, $quadro['linhas']);
        $linha = $quadro['linhas'][0];
        self::assertSame(['bi-check-circle-fill', '#1e7b45', OficinaDePdfAssinado::TITULAR, '***.982.247-**'], [$linha['icone'], $linha['cor'], $linha['nome'], $linha['documento']]);
        self::assertNotNull($linha['em']);
        self::assertEqualsWithDelta(time(), (new \DateTimeImmutable($linha['em']))->getTimestamp(), 300);
        self::assertSame('PAdES · Íntegra: arquivo sem alteração desde esta assinatura · ' . self::EMISSOR, $linha['detalhe']);
        self::assertSame(self::NOTA, $quadro['nota']);
        self::assertSame(self::AVISO_CARIMBO, $quadro['aviso']);
        self::assertTrue($quadro['carimbo']);
    }

    #[TestDox('duas assinaturas: "2 assinaturas digitais reconhecidas"; a primeira fica preservada com acréscimos posteriores')]
    public function testDuasAssinaturas(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado();
        $pdf = OficinaDePdfAssinado::assinar(OficinaDePdfAssinado::acrescimoParaAssinar($primeira), OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')));

        $quadro = $this->apresentacao->quadro($this->motor->conferir($pdf));

        self::assertNotNull($quadro);
        self::assertSame('Documento já assinado: 2 assinaturas digitais reconhecidas', $quadro['titulo']);
        self::assertStringContainsString('Íntegra: esta versão está preservada e há acréscimos posteriores', $quadro['linhas'][0]['detalhe']);
        self::assertStringContainsString('Íntegra: arquivo sem alteração desde esta assinatura', $quadro['linhas'][1]['detalhe']);
    }

    #[TestDox('assinatura que não confere: título de atenção, tudo vermelho, motivo no detalhe, sem carimbo e com o aviso de bloqueio')]
    public function testAlterada(): void
    {
        $pdf = OficinaDePdfAssinado::pdfAssinado();
        $alterado = substr_replace($pdf, '%PDF-1.6', 0, 8);
        $resultado = $this->motor->conferir($alterado);

        $quadro = $this->apresentacao->quadro($resultado);

        self::assertNotNull($quadro);
        self::assertSame('Atenção: há assinatura que não confere com o arquivo', $quadro['titulo']);
        self::assertSame('#a3232b', $quadro['cor']);
        self::assertSame(['bi-x-circle-fill', '#a3232b'], [$quadro['linhas'][0]['icone'], $quadro['linhas'][0]['cor']]);
        self::assertNotSame('', $resultado->assinaturas[0]->motivo);
        self::assertStringContainsString($resultado->assinaturas[0]->motivo, $quadro['linhas'][0]['detalhe']);
        self::assertFalse($quadro['carimbo']);
        self::assertSame(self::AVISO_BLOQUEADO, $quadro['aviso']);
    }

    #[TestDox('DocMDP 1: a nota ganha a frase do bloqueio e o PDF não aceita carimbo')]
    public function testDocMdpUm(): void
    {
        $quadro = $this->apresentacao->quadro($this->motor->conferir(OficinaDePdfAssinado::pdfAssinado('rsa', \sprintf(OficinaDePdfAssinado::DOCMDP, 1))));

        self::assertNotNull($quadro);
        self::assertSame(self::NOTA . self::NOTA_DOCMDP, $quadro['nota']);
        self::assertFalse($quadro['carimbo']);
        self::assertSame(self::AVISO_BLOQUEADO, $quadro['aviso']);
    }

    #[TestDox('parágrafo de dc:374 com o número, os nomes e o SHA-256 dos bytes enviados')]
    public function testParagrafo(): void
    {
        $pdf = OficinaDePdfAssinado::pdfAssinado();
        $original = OriginalJaAssinado::de($pdf, $this->motor->conferir($pdf));

        self::assertNotNull($original);
        self::assertSame(hash('sha256', $pdf), $original->sha256);
        self::assertSame($pdf, $original->bytes);
        self::assertSame(
            'Este documento já continha 1 assinatura(s) digital(is) de ' . OficinaDePdfAssinado::TITULAR . '. O arquivo original foi preservado sem alteração, com hash SHA-256 ' . $original->sha256 . '.',
            $this->apresentacao->paragrafo($original->conferencia, $original->sha256),
        );
    }
}
