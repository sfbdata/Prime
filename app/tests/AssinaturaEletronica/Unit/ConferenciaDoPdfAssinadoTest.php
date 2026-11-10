<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\Cadeia\RegraDaCadeia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDoPdfAssinado;
use App\AssinaturaEletronica\Service\ResultadoDoPdfAssinado;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A conferência do PDF devolvido antes do validador de cadeia (etapa 5, bloco 5C), com o motor e a regra de verdade e
 * PDFs montados pela {@see OficinaDePdfAssinado}: a versão base é a última do documento, cada código da regra e do CPF
 * vira o erro do catálogo público, e nada é gravado (a conferência nem conhece o validador).
 */
#[CoversClass(ConferenciaDoPdfAssinado::class)]
#[CoversClass(ResultadoDoPdfAssinado::class)]
final class ConferenciaDoPdfAssinadoTest extends TestCase
{
    private const SHA = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private ArmazenamentoEmMemoria $disco;
    private DocumentoParaAssinar $documento;
    private ?VersaoDoPdf $base = null;

    protected function setUp(): void
    {
        $this->disco = new ArmazenamentoEmMemoria();
        $tenant = (new Tenant())->setName('Escritório da Conferência');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 9);
        $this->documento = new DocumentoParaAssinar($tenant, null, new \DateTimeImmutable('2026-11-09 08:00:00'));
        $this->documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato',
            'signatarios' => [['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'nivel' => 'govbr', 'cpf' => OficinaDePdfAssinado::CPF]],
        ]), [], new \DateTimeImmutable('2026-11-09 08:00:00'));
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($this->documento, 1);
    }

    #[TestDox('PDF devolvido sobre a base assinada: aceito, com as 2 assinaturas do arquivo e as anteriores preservadas; só a base é lida e nada é gravado')]
    public function testAceitoComAsAnterioresPreservadas(): void
    {
        $base = OficinaDePdfAssinado::pdfAssinado();
        $this->comBase($base);

        $resultado = $this->conferencia()->conferir($this->documento, OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base), OficinaDePdfAssinado::CPF);

        self::assertTrue($resultado->aceitou());
        self::assertNull($resultado->erro);
        self::assertSame([2, true], [$resultado->assinaturas(), $resultado->preservadas()]);
        self::assertSame(ConferenciaDoPrefixo::Confere, $resultado->motor?->prefixo, 'o motor conferiu contra a base');
        self::assertSame(1, $resultado->motor?->assinaturasNovas);
        self::assertSame([ChavesDeAssinaturaEletronica::versao($this->base ?? self::fail('sem base'))->comoTexto()], $this->disco->lidas);
        self::assertSame([], $this->disco->gravadas, 'nada é gravado no armazenamento');
    }

    #[TestDox('base sem assinatura digital (o PDF para assinar): aceito com 1 assinatura e sem as anteriores preservadas')]
    public function testAceitoSemAnteriores(): void
    {
        $base = OficinaDePdfAssinado::pdfSemAssinatura();
        $this->comBase($base);

        $resultado = $this->conferencia()->conferir($this->documento, self::assinadoSobreBaseSemAssinatura($base), OficinaDePdfAssinado::CPF);

        self::assertTrue($resultado->aceitou(), (string) $resultado->erro);
        self::assertSame([1, false], [$resultado->assinaturas(), $resultado->preservadas()]);
    }

    #[TestDox('documento sem versão guardada: "prefixo", sem rodar o motor nem ler o armazenamento')]
    public function testSemBase(): void
    {
        $resultado = $this->conferencia()->conferir($this->documento, OficinaDePdfAssinado::pdfAssinadoComVinculo(), OficinaDePdfAssinado::CPF);

        self::assertSame(ResultadoDoPdfAssinado::PREFIXO, $resultado->erro);
        self::assertNull($resultado->motor);
        self::assertSame([0, false], [$resultado->assinaturas(), $resultado->preservadas()]);
        self::assertSame([], $this->disco->lidas);
    }

    /** @return iterable<string, array{\Closure(string): string, string}> */
    public static function recusasDaRegra(): iterable
    {
        yield 'prefixo que não confere (outro PDF assinado)' => [
            static fn (string $base): string => OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, OficinaDePdfAssinado::pdfAssinado('rsa2')),
            ResultadoDoPdfAssinado::PREFIXO,
        ];
        yield 'nenhuma assinatura nova (a própria base)' => [static fn (string $base): string => $base, ResultadoDoPdfAssinado::SEM_ASSINATURA_NOVA];
        yield 'sem assinatura digital' => [static fn (string $base): string => OficinaDePdfAssinado::pdfSemAssinatura(), ResultadoDoPdfAssinado::SEM_ASSINATURA];
        yield 'não é PDF' => [static fn (string $base): string => 'só texto', ResultadoDoPdfAssinado::ILEGIVEL];
        yield 'assinatura nova alterada' => [static fn (string $base): string => self::alterado(OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base)), ResultadoDoPdfAssinado::ALTERADA];
        yield 'acréscimo que muda o conteúdo (a página)' => [
            static fn (string $base): string => OficinaDePdfAssinado::assinar(
                OficinaDePdfAssinado::acrescimoParaAssinar($base, trocas: [3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 600 842]/Annots[4 0 R 6 0 R]>>']),
                OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')),
            ),
            ResultadoDoPdfAssinado::ALTERADA,
        ];
        yield 'acréscimo que o motor não lê' => [
            static fn (string $base): string => OficinaDePdfAssinado::assinar(
                OficinaDePdfAssinado::acrescimoParaAssinar($base, antesDoIndice: "8 1 obj\n<<>>\nendobj\n"),
                OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')),
            ),
            ResultadoDoPdfAssinado::ALTERADA,
        ];
    }

    /** @param \Closure(string): string $devolvido */
    #[DataProvider('recusasDaRegra')]
    #[TestDox('regra antes do validador: $_dataName')]
    public function testRecusaDaRegra(\Closure $devolvido, string $erro): void
    {
        $base = OficinaDePdfAssinado::pdfAssinado();
        $this->comBase($base);

        $resultado = $this->conferencia()->conferir($this->documento, $devolvido($base), OficinaDePdfAssinado::CPF);

        self::assertSame($erro, $resultado->erro, (string) $resultado->motor?->motivo);
        self::assertFalse($resultado->aceitou());
        self::assertFalse($resultado->preservadas());
        self::assertNotNull($resultado->motor, 'o motor rodou');
        self::assertSame([], $this->disco->gravadas);
    }

    /** @return iterable<string, array{\Closure(string): string, ?string, ?string}> */
    public static function cpfs(): iterable
    {
        $vinculado = static fn (string $base): string => OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base);
        $semCpf = static fn (string $base): string => OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($base),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec'), OficinaDePdfAssinado::certificado(OficinaDePdfAssinado::chave('ec'), 'SEM DOCUMENTO')),
        );
        $semVinculo = static fn (string $base): string => OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($base),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')),
        );

        yield 'certificado sem CPF' => [$semCpf, OficinaDePdfAssinado::CPF, ResultadoDoPdfAssinado::SEM_CPF];
        yield 'CPF do certificado diferente do cadastrado' => [$vinculado, OficinaDePdfAssinado::CPF_2, ResultadoDoPdfAssinado::CPF_DIFERENTE];
        yield 'CPF que o motor não lê (certificado não vinculado à assinatura)' => [$semVinculo, OficinaDePdfAssinado::CPF, ResultadoDoPdfAssinado::CPF_NAO_LIDO];
        yield 'sem CPF cadastrado e certificado sem CPF' => [$semCpf, null, ResultadoDoPdfAssinado::SEM_CPF];
        yield 'sem CPF cadastrado e certificado com CPF' => [$vinculado, null, null];
        yield 'sem CPF cadastrado, nova com CPF seguida do carimbo de documento (sem CPF)' => [self::novaComCarimbo(...), null, null];
    }

    /** @param \Closure(string): string $devolvido */
    #[DataProvider('cpfs')]
    #[TestDox('CPF: $_dataName')]
    public function testCpf(\Closure $devolvido, ?string $cpfEsperado, ?string $erro): void
    {
        $base = OficinaDePdfAssinado::pdfAssinado();
        $this->comBase($base);

        $resultado = $this->conferencia()->conferir($this->documento, $devolvido($base), $cpfEsperado);

        self::assertSame($erro, $resultado->erro, (string) $resultado->motor?->motivo);
    }

    #[TestDox('o carimbo de documento depois da nova é a última assinatura do arquivo, e o CPF que vale é o da nova')]
    public function testCarimboDepoisDaNovaEAUltima(): void
    {
        $base = OficinaDePdfAssinado::pdfAssinado();
        $this->comBase($base);

        $resultado = $this->conferencia()->conferir($this->documento, self::novaComCarimbo($base), null);

        self::assertNull($resultado->motor?->ultimaAssinatura()?->cpf, 'o carimbo não traz CPF');
        self::assertTrue($resultado->motor?->ultimaAssinatura()?->carimboDoTempo());
        self::assertTrue($resultado->aceitou());
        self::assertSame(3, $resultado->assinaturas());
    }

    #[TestDox('o arquivo da versão base sumiu: a falha sobe, não vira recusa do arquivo enviado')]
    public function testArquivoDaBaseAusente(): void
    {
        $this->comBase(OficinaDePdfAssinado::pdfAssinado(), semear: false);

        $this->expectException(ArquivoNaoEncontrado::class);
        $this->conferencia()->conferir($this->documento, OficinaDePdfAssinado::pdfAssinadoComVinculo(), OficinaDePdfAssinado::CPF);
    }

    #[TestDox('a chave do texto de cada erro é a do bloco certificado do catálogo público')]
    public function testChaveDoCatalogo(): void
    {
        self::assertSame('assinatura_eletronica_publica.js.certificado.erro.prefixo', ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::PREFIXO));
        self::assertSame('assinatura_eletronica_publica.js.certificado.erro.cpf_nao_lido', ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::CPF_NAO_LIDO));
    }

    /** A conferência sobre o disco em memória e o repositório de versões que só sabe a `ultima` (nunca grava). */
    private function conferencia(): ConferenciaDoPdfAssinado
    {
        $versoes = $this->createMock(VersaoDoPdfRepository::class);
        $versoes->method('ultima')->willReturnCallback(fn (DocumentoParaAssinar $documento): ?VersaoDoPdf => $documento === $this->documento ? $this->base : null);
        $versoes->expects(self::never())->method('salvar');
        $versoes->expects(self::never())->method('remover');

        return new ConferenciaDoPdfAssinado($versoes, $this->disco, new ConferenciaDePdf(), new RegraDaCadeia());
    }

    private function comBase(string $pdf, bool $semear = true): void
    {
        $this->base = VersaoDoPdf::original($this->documento, 1, 'original.pdf', 'Contrato assinado.pdf', self::SHA, \strlen($pdf), ['v' => 1], new \DateTimeImmutable());
        if ($semear) {
            $this->disco->semear(ChavesDeAssinaturaEletronica::versao($this->base), $pdf);
        }
    }

    /** A assinatura sobre um PDF sem campo de assinatura: o acréscimo cria o campo 6 e o /Sig 7, com o vínculo. */
    private static function assinadoSobreBaseSemAssinatura(string $base): string
    {
        $chave = OficinaDePdfAssinado::chave('ec');
        $certificado = OficinaDePdfAssinado::certificado($chave);

        return OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($base, trocas: [
                1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[6 0 R]/SigFlags 3>>>>',
                3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Annots[6 0 R]>>',
            ]),
            OficinaDePdfAssinado::cmsManual($chave, $certificado, atributosExtras: OficinaDePdfAssinado::vinculo($certificado)),
        );
    }

    /**
     * A assinatura nova (certificado com CPF, sem o vínculo) e, depois dela, o carimbo de documento (`ETSI.RFC3161`,
     * certificado da ACT sem CPF), como em {@see RegraDaCadeiaComOMotorTest}: a última assinatura do arquivo é o carimbo.
     */
    private static function novaComCarimbo(string $base): string
    {
        $comNova = OficinaDePdfAssinado::assinar(OficinaDePdfAssinado::acrescimoParaAssinar($base), OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')));

        return OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($comNova, '', 'ETSI.RFC3161', [
                1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R 6 0 R 8 0 R]/SigFlags 3>>>>',
                3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Annots[4 0 R 6 0 R 8 0 R]>>',
            ], widget: 8),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec'), OficinaDePdfAssinado::certificado(OficinaDePdfAssinado::chave('ec'), 'ACT DE TESTE')),
        );
    }

    /** Um byte trocado no conteúdo coberto pela última assinatura. */
    private static function alterado(string $pdf): string
    {
        return substr_replace($pdf, 'm', (int) strrpos($pdf, '/MediaBox[0 0 595 842]') + 1, 1);
    }
}
