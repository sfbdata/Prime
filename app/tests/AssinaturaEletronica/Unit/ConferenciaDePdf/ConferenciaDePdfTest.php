<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoCpf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\FormatoNaoReconhecido;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LeitorDeAssinaturaCms;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LeitorDeCertificado;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimiteExcedido;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VerificadorDeChave;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VinculoDoCertificado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConferenciaDePdf::class)]
#[CoversClass(LeitorDeAssinaturaCms::class)]
#[CoversClass(LeitorDeCertificado::class)]
#[CoversClass(ResultadoDaConferencia::class)]
final class ConferenciaDePdfTest extends TestCase
{
    private ConferenciaDePdf $motor;

    protected function setUp(): void
    {
        $this->motor = new ConferenciaDePdf();
    }

    /** @return iterable<string, array{string, string}> */
    public static function chavesValidas(): iterable
    {
        yield 'RSA PKCS#1 v1.5' => ['rsa', 'SHA-256'];
        yield 'ECDSA P-256' => ['ec', 'SHA-256'];
        yield 'ECDSA P-384' => ['ec384', 'SHA-256'];
    }

    #[DataProvider('chavesValidas')]
    #[TestDox('assinatura $_dataName íntegra: faixa, resumo, chave e os dados do certificado')]
    public function testAssinaturaValida(string $chave, string $algoritmo): void
    {
        $pdf = OficinaDePdfAssinado::pdfAssinado($chave);

        $resultado = $this->motor->conferir($pdf);

        self::assertSame(SituacaoDaConferencia::Integro, $resultado->situacao);
        self::assertSame('A assinatura confere com o conteúdo e com o certificado', $resultado->motivo);
        self::assertSame(\strlen($pdf), $resultado->tamanho);
        self::assertCount(1, $resultado->assinaturas);
        $a = $resultado->assinaturas[0];
        self::assertSame(VereditoDaAssinatura::Integra, $a->veredito);
        self::assertSame(ConferenciaDePdf::MOTIVO_INTEGRA, $a->motivo);
        self::assertSame(1, $a->ordem);
        self::assertTrue($a->faixaValida);
        self::assertTrue($a->cobreODocumento);
        self::assertSame(0, $a->bytesDepois);
        self::assertSame(\strlen($pdf), $a->fim);
        self::assertSame(0, $a->faixaDeBytes[0]);
        self::assertSame(\strlen($pdf), $a->faixaDeBytes[2] + $a->faixaDeBytes[3]);
        self::assertSame('PAdES', $a->tipo);
        self::assertSame($algoritmo, $a->algoritmoDoResumo);
        self::assertTrue($a->resumoConfere);
        self::assertTrue($a->chaveConfere);
        self::assertSame(OficinaDePdfAssinado::TITULAR, $a->titular);
        self::assertSame(OficinaDePdfAssinado::CPF, $a->cpf);
        self::assertNull($a->cnpj);
        self::assertSame('***.982.247-**', $a->documentoMascarado);
        self::assertSame(OficinaDePdfAssinado::TITULAR . ':' . OficinaDePdfAssinado::CPF, $a->emissor);
        self::assertTrue($a->emissorDeclaraIcpBrasil);
        self::assertSame(VinculoDoCertificado::NaoVinculado, $a->vinculoDoCertificado, 'o openssl_cms_sign não põe o signingCertificateV2');
        self::assertNotNull($a->numeroDeSerie);
        self::assertNotNull($a->validoDesde);
        self::assertNotNull($a->validoAte);
        self::assertEqualsWithDelta(time() + 365 * 86400, $a->validoAte->getTimestamp(), 300);
        self::assertNotNull($a->horaDeclarada);
        self::assertEqualsWithDelta(time(), $a->horaDeclarada->getTimestamp(), 300, 'signingTime do CMS manda sobre o /M');
        self::assertSame('+00:00', $a->horaDeclarada->format('P'));
        self::assertNull($a->docMdp);
        self::assertSame(0, $resultado->docMdp);
        self::assertFalse($resultado->cifrado);
        self::assertTrue($resultado->permiteCarimbo);
        self::assertFalse($resultado->alteradoDepoisDaCertificacao);
        self::assertSame(ConferenciaDoPrefixo::NaoPedida, $resultado->prefixo);
        self::assertSame(ConferenciaDoCpf::NaoPedida, $resultado->cpf);
        self::assertSame(0, $resultado->assinaturasNovas);
        self::assertSame($a, $resultado->ultimaAssinatura());
    }

    #[TestDox('RSA-PSS montado à mão (PHP 8.2 não assina PSS) confere; com um bit trocado a chave não confere')]
    public function testRsaPss(): void
    {
        $chave = OficinaDePdfAssinado::chave();
        $pdf = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::pdfParaAssinar(),
            OficinaDePdfAssinado::cmsManual($chave, OficinaDePdfAssinado::certificado($chave), pss: true),
        );

        $integro = $this->motor->conferir($pdf);
        $trocado = $this->motor->conferir(OficinaDePdfAssinado::alterarCms($pdf, self::trocarUltimoByte(...)));

        self::assertSame(SituacaoDaConferencia::Integro, $integro->situacao);
        self::assertTrue($integro->assinaturas[0]->chaveConfere);
        self::assertSame(VereditoDaAssinatura::Alterada, $trocado->assinaturas[0]->veredito);
        self::assertSame(ConferenciaDePdf::MOTIVO_CHAVE_NAO_CONFERE, $trocado->assinaturas[0]->motivo);
        self::assertTrue($trocado->assinaturas[0]->resumoConfere);
        self::assertFalse($trocado->assinaturas[0]->chaveConfere);
    }

    /** @return iterable<string, array{string}> */
    public static function partesDaFaixa(): iterable
    {
        yield 'na primeira parte (página)' => ['/MediaBox[0 0 595 842]'];
        yield 'na segunda parte (trailer)' => ['/Root 1 0 R'];
    }

    #[DataProvider('partesDaFaixa')]
    #[TestDox('um byte alterado $_dataName: o resumo não confere')]
    public function testByteAlteradoNaFaixa(string $trecho): void
    {
        $pdf = OficinaDePdfAssinado::pdfAssinado();
        $posicao = strrpos($pdf, $trecho);
        self::assertNotFalse($posicao);
        $alterado = substr_replace($pdf, strtoupper($trecho[1]) === $trecho[1] ? strtolower($trecho[1]) : strtoupper($trecho[1]), $posicao + 1, 1);

        $resultado = $this->motor->conferir($alterado);

        self::assertSame(SituacaoDaConferencia::Alterado, $resultado->situacao);
        self::assertSame(ConferenciaDePdf::MOTIVO_CONTEUDO_ALTERADO, $resultado->motivo);
        self::assertSame(VereditoDaAssinatura::Alterada, $resultado->assinaturas[0]->veredito);
        self::assertFalse($resultado->assinaturas[0]->resumoConfere);
        self::assertFalse($resultado->permiteCarimbo);
    }

    #[TestDox('assinatura de outra chave RSA no lugar da original: o resumo confere e a chave não')]
    public function testAssinaturaDeOutraChave(): void
    {
        $original = OficinaDePdfAssinado::pdfAssinado('rsa');
        $daOutra = OficinaDePdfAssinado::assinar(OficinaDePdfAssinado::pdfParaAssinar(), OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('rsa2')));
        $assinaturaDaOutra = '';
        OficinaDePdfAssinado::alterarCms($daOutra, static function (string $der) use (&$assinaturaDaOutra): string {
            $assinaturaDaOutra = substr($der, -256);

            return $der;
        });

        $trocado = OficinaDePdfAssinado::alterarCms($original, static fn (string $der): string => substr($der, 0, -256) . $assinaturaDaOutra);
        $resultado = $this->motor->conferir($trocado);

        $a = $resultado->assinaturas[0];
        self::assertSame(VereditoDaAssinatura::Alterada, $a->veredito);
        self::assertSame(ConferenciaDePdf::MOTIVO_CHAVE_NAO_CONFERE, $a->motivo);
        self::assertTrue($a->resumoConfere);
        self::assertFalse($a->chaveConfere);
        self::assertSame(SituacaoDaConferencia::Alterado, $resultado->situacao);
    }

    /** @return iterable<string, array{string}> */
    public static function chavesComBitTrocado(): iterable
    {
        yield 'RSA' => ['rsa'];
        yield 'ECDSA' => ['ec'];
    }

    #[DataProvider('chavesComBitTrocado')]
    #[TestDox('assinatura $_dataName com o último byte trocado: a chave não confere')]
    public function testAssinaturaComByteTrocado(string $chave): void
    {
        $pdf = OficinaDePdfAssinado::alterarCms(OficinaDePdfAssinado::pdfAssinado($chave), self::trocarUltimoByte(...));

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::Alterada, $a->veredito);
        self::assertSame(ConferenciaDePdf::MOTIVO_CHAVE_NAO_CONFERE, $a->motivo);
    }

    #[TestDox('bytes acrescentados depois da assinatura: ela continua íntegra, mas não cobre o arquivo e o arquivo fica não conferido')]
    public function testFaixaQueNaoCobreOArquivo(): void
    {
        $pdf = OficinaDePdfAssinado::pdfAssinado() . "% acrescimo\n";

        $resultado = $this->motor->conferir($pdf);

        $a = $resultado->assinaturas[0];
        self::assertSame(VereditoDaAssinatura::Integra, $a->veredito);
        self::assertFalse($a->cobreODocumento);
        self::assertSame(12, $a->bytesDepois);
        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame(ConferenciaDePdf::MOTIVO_ACRESCIMO, $resultado->motivo);
    }

    #[TestDox('segunda assinatura incremental: as duas íntegras, a nova começa com a primeira versão e o CPF é o da nova')]
    public function testSegundaAssinaturaIncremental(): void
    {
        $chaveEc = OficinaDePdfAssinado::chave('ec');
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $segunda = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($primeira),
            OficinaDePdfAssinado::cmsDoOpenssl($chaveEc, OficinaDePdfAssinado::certificado($chaveEc, 'BELTRANO:' . OficinaDePdfAssinado::CPF_2)),
        );

        $resultado = $this->motor->conferir($segunda, $primeira, '111.444.777-35');

        self::assertSame(SituacaoDaConferencia::Integro, $resultado->situacao);
        self::assertSame('As 2 assinaturas conferem com o conteúdo e com os certificados', $resultado->motivo);
        [$a, $b] = $resultado->assinaturas;
        self::assertSame([1, 2], [$a->ordem, $b->ordem]);
        self::assertSame(\strlen($primeira), $a->fim);
        self::assertFalse($a->cobreODocumento);
        self::assertSame(\strlen($segunda) - \strlen($primeira), $a->bytesDepois);
        self::assertTrue($b->cobreODocumento);
        self::assertSame([VereditoDaAssinatura::Integra, VereditoDaAssinatura::Integra], [$a->veredito, $b->veredito]);
        self::assertSame(['FULANA DE TAL', 'BELTRANO'], [$a->titular, $b->titular]);
        self::assertSame(ConferenciaDoPrefixo::Confere, $resultado->prefixo);
        self::assertSame(1, $resultado->assinaturasNovas);
        self::assertSame(ConferenciaDoCpf::Confere, $resultado->cpf);
    }

    #[TestDox('versão nova que não começa com a anterior (um byte, anterior maior ou vazia): o prefixo não confere')]
    public function testVersaoNovaQueNaoComecaComAAnterior(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa');
        $segunda = OficinaDePdfAssinado::assinar(OficinaDePdfAssinado::acrescimoParaAssinar($primeira), OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')));
        $outraPrimeira = substr_replace($primeira, 'X', 20, 1);

        self::assertSame(ConferenciaDoPrefixo::NaoConfere, $this->motor->conferir($segunda, $outraPrimeira)->prefixo);
        self::assertSame(ConferenciaDoPrefixo::NaoConfere, $this->motor->conferir($primeira, $segunda)->prefixo);
        self::assertSame(ConferenciaDoPrefixo::NaoConfere, $this->motor->conferir($segunda, '')->prefixo);
        self::assertSame(ConferenciaDoPrefixo::Confere, $this->motor->conferir($primeira, $primeira)->prefixo);
        self::assertSame(0, $this->motor->conferir($primeira, $primeira)->assinaturasNovas);
        self::assertSame('A nova versão não começa com a anterior', ConferenciaDoPrefixo::NaoConfere->motivo());
    }

    /** @return iterable<string, array{int, bool, SituacaoDaConferencia, bool}> */
    public static function docMdp(): iterable
    {
        yield 'P=1 com alteração depois' => [1, true, SituacaoDaConferencia::Alterado, false];
        yield 'P=1 sem alteração depois' => [1, false, SituacaoDaConferencia::Integro, false];
        yield 'P=2 com assinatura depois' => [2, true, SituacaoDaConferencia::Integro, false];
        yield 'P=3 com assinatura depois' => [3, true, SituacaoDaConferencia::Integro, true];
    }

    #[DataProvider('docMdp')]
    #[TestDox('DocMDP $_dataName')]
    public function testDocMdp(int $p, bool $comSegunda, SituacaoDaConferencia $situacao, bool $permiteCarimbo): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado('rsa', \sprintf(OficinaDePdfAssinado::DOCMDP, $p));
        $pdf = $comSegunda
            ? OficinaDePdfAssinado::assinar(OficinaDePdfAssinado::acrescimoParaAssinar($primeira), OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')))
            : $primeira;

        $resultado = $this->motor->conferir($pdf);

        self::assertSame($p, $resultado->docMdp);
        self::assertSame($p, $resultado->assinaturas[0]->docMdp);
        self::assertSame($situacao, $resultado->situacao);
        self::assertSame($permiteCarimbo, $resultado->permiteCarimbo);
        self::assertSame($p === 1 && $comSegunda, $resultado->alteradoDepoisDaCertificacao);
        foreach ($resultado->assinaturas as $assinatura) {
            self::assertSame(VereditoDaAssinatura::Integra, $assinatura->veredito, 'cada assinatura segue íntegra');
        }
        if ($situacao === SituacaoDaConferencia::Alterado) {
            self::assertSame(ConferenciaDePdf::MOTIVO_DOCMDP, $resultado->motivo);
        }
    }

    #[TestDox('DocMDP sem /P vale 2 e /P fora de 1 a 3 também')]
    public function testDocMdpSemP(): void
    {
        $semP = OficinaDePdfAssinado::pdfAssinado('rsa', '/Reference[<</Type/SigRef/TransformMethod/DocMDP>>]');
        $comP7 = OficinaDePdfAssinado::pdfAssinado('rsa', \sprintf(OficinaDePdfAssinado::DOCMDP, 7));

        self::assertSame(2, $this->motor->conferir($semP)->docMdp);
        self::assertSame(2, $this->motor->conferir($comP7)->docMdp);
    }

    /** @return iterable<string, array{?string, ConferenciaDoCpf}> */
    public static function cpfsEsperados(): iterable
    {
        yield 'o mesmo, com pontuação' => ['529.982.247-25', ConferenciaDoCpf::Confere];
        yield 'o mesmo, só dígitos' => [OficinaDePdfAssinado::CPF, ConferenciaDoCpf::Confere];
        yield 'outro CPF' => [OficinaDePdfAssinado::CPF_2, ConferenciaDoCpf::NaoConfere];
        yield 'CPF incompleto' => ['5299822472', ConferenciaDoCpf::NaoConfere];
        yield 'vazio' => ['', ConferenciaDoCpf::NaoConfere];
        yield 'não informado' => [null, ConferenciaDoCpf::NaoPedida];
    }

    #[DataProvider('cpfsEsperados')]
    #[TestDox('CPF esperado $_dataName')]
    public function testCpfEsperado(?string $cpf, ConferenciaDoCpf $esperado): void
    {
        self::assertSame($esperado, $this->motor->conferir(OficinaDePdfAssinado::pdfAssinado(), null, $cpf)->cpf);
    }

    #[TestDox('CPF esperado sem CPF no certificado, sem assinatura ou sem assinatura nova depois da versão anterior')]
    public function testCpfEsperadoSemAlvo(): void
    {
        $chave = OficinaDePdfAssinado::chave();
        $semCpf = OficinaDePdfAssinado::pdfAssinado('rsa', '', OficinaDePdfAssinado::certificado($chave, 'SEM DOCUMENTO'));
        $assinado = OficinaDePdfAssinado::pdfAssinado();

        self::assertSame(ConferenciaDoCpf::SemCpfNoCertificado, $this->motor->conferir($semCpf, null, OficinaDePdfAssinado::CPF)->cpf);
        self::assertSame(ConferenciaDoCpf::SemAssinatura, $this->motor->conferir(OficinaDePdfAssinado::pdfSemAssinatura(), null, OficinaDePdfAssinado::CPF)->cpf);
        self::assertSame(ConferenciaDoCpf::SemAssinatura, $this->motor->conferir($assinado, $assinado, OficinaDePdfAssinado::CPF)->cpf);
        self::assertSame(ConferenciaDoCpf::SemAssinatura, $this->motor->conferir('lixo', null, OficinaDePdfAssinado::CPF)->cpf);
        self::assertSame('O certificado não traz CPF', ConferenciaDoCpf::SemCpfNoCertificado->motivo());
    }

    /** @return iterable<string, array{string, ?string, ?string, ?string, ?string, ?string, bool}> */
    public static function documentosDoCertificado(): iterable
    {
        yield 'CPF no otherName da ICP-Brasil' => ['BELTRANA DE TAL', OficinaDePdfAssinado::outroNomeComCpf(), 'BELTRANA DE TAL', OficinaDePdfAssinado::CPF, null, '***.982.247-**', true];
        yield 'otherName manda sobre o CN' => ['BELTRANA:' . OficinaDePdfAssinado::CPF_2, OficinaDePdfAssinado::outroNomeComCpf(), 'BELTRANA', OficinaDePdfAssinado::CPF, null, '***.982.247-**', true];
        yield 'CNPJ no CN (e-CNPJ)' => ['ESCRITORIO MODELO LTDA:' . OficinaDePdfAssinado::CNPJ, null, 'ESCRITORIO MODELO LTDA', null, OficinaDePdfAssinado::CNPJ, '11.222.333/0001-81', true];
        yield 'CNPJ no otherName' => ['ESCRITORIO MODELO LTDA', OficinaDePdfAssinado::outroNomeComCnpj(), 'ESCRITORIO MODELO LTDA', null, OficinaDePdfAssinado::CNPJ, '11.222.333/0001-81', true];
        yield 'sem documento e fora da ICP-Brasil' => ['FULANO', null, 'FULANO', null, null, null, false];
    }

    #[DataProvider('documentosDoCertificado')]
    #[TestDox('certificado com $_dataName')]
    public function testDocumentosDoCertificado(string $cn, ?string $outroNome, ?string $titular, ?string $cpf, ?string $cnpj, ?string $mascarado, bool $icp): void
    {
        $chave = OficinaDePdfAssinado::chave();
        $certificado = OficinaDePdfAssinado::certificado($chave, $cn, $outroNome, $icp ? 'ICP-Brasil' : 'Autoridade de Teste');

        $a = $this->motor->conferir(OficinaDePdfAssinado::pdfAssinado('rsa', '', $certificado))->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::Integra, $a->veredito);
        self::assertSame([$titular, $cpf, $cnpj, $mascarado, $icp], [$a->titular, $a->cpf, $a->cnpj, $a->documentoMascarado, $a->emissorDeclaraIcpBrasil]);
    }

    #[TestDox('sem atributos assinados: igual ao protótipo, a chave confere mas o resumo não é conferido; hora do /M com fuso')]
    public function testSemAtributosAssinados(): void
    {
        $chave = OficinaDePdfAssinado::chave();
        $pdf = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::pdfParaAssinar(),
            OficinaDePdfAssinado::cmsManual($chave, OficinaDePdfAssinado::certificado($chave), atributos: false),
        );

        $resultado = $this->motor->conferir($pdf);

        $a = $resultado->assinaturas[0];
        self::assertSame(VereditoDaAssinatura::NaoConferida, $a->veredito);
        self::assertSame(LeitorDeAssinaturaCms::MOTIVO_SEM_ATRIBUTOS, $a->motivo);
        self::assertNull($a->resumoConfere);
        self::assertTrue($a->chaveConfere);
        self::assertSame('2026-11-09T15:00:00+00:00', $a->horaDeclarada?->format(\DATE_ATOM));
        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame(VinculoDoCertificado::NaoVinculado, $a->vinculoDoCertificado);
    }

    /** @return iterable<string, array{string}> */
    public static function vinculosValidos(): iterable
    {
        $d = MontadorDeDer::class;
        yield 'signingCertificateV2 com o SHA-256 padrão' => ['v2'];
        yield 'signingCertificateV2 com SHA-512 declarado' => [$d::OID_SHA512];
        yield 'signingCertificate v1 (SHA-1)' => ['v1'];
    }

    #[DataProvider('vinculosValidos')]
    #[TestDox('certificado vinculado pelo $_dataName: íntegra e vinculado')]
    public function testCertificadoVinculado(string $forma): void
    {
        $chave = OficinaDePdfAssinado::chave();
        $certificado = OficinaDePdfAssinado::certificado($chave);
        $vinculo = match ($forma) {
            'v2' => OficinaDePdfAssinado::vinculo($certificado),
            'v1' => OficinaDePdfAssinado::vinculo($certificado, false),
            default => OficinaDePdfAssinado::vinculo($certificado, true, $forma),
        };
        $pdf = OficinaDePdfAssinado::assinar(OficinaDePdfAssinado::pdfParaAssinar(), OficinaDePdfAssinado::cmsManual($chave, $certificado, atributosExtras: $vinculo));

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::Integra, $a->veredito);
        self::assertSame(VinculoDoCertificado::Vinculado, $a->vinculoDoCertificado);
    }

    #[TestDox('certificado vinculado por hash fora da lista (MD5): íntegra, vínculo não conferido')]
    public function testVinculoComHashNaoSuportado(): void
    {
        $chave = OficinaDePdfAssinado::chave();
        $certificado = OficinaDePdfAssinado::certificado($chave);
        $vinculo = OficinaDePdfAssinado::vinculo($certificado, true, MontadorDeDer::OID_MD5);
        $pdf = OficinaDePdfAssinado::assinar(OficinaDePdfAssinado::pdfParaAssinar(), OficinaDePdfAssinado::cmsManual($chave, $certificado, atributosExtras: $vinculo));

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::Integra, $a->veredito);
        self::assertSame(VinculoDoCertificado::NaoConferido, $a->vinculoDoCertificado);
    }

    /** Troca o titular e o CPF dentro do certificado guardado no CMS (mesmo tamanho), sem mexer na chave. */
    private static function certificadoTrocado(string $pdf): string
    {
        return OficinaDePdfAssinado::alterarCms($pdf, static fn (string $der): string => str_replace(
            [OficinaDePdfAssinado::TITULAR, OficinaDePdfAssinado::CPF],
            ['BELTRA DE TAL', OficinaDePdfAssinado::CPF_2],
            $der,
        ));
    }

    #[TestDox('certificado trocado no /Contents com o signingCertificateV2: alterada, mesmo com a chave conferindo')]
    public function testCertificadoTrocadoComVinculo(): void
    {
        $pdf = self::certificadoTrocado(OficinaDePdfAssinado::pdfAssinadoComVinculo());

        $resultado = $this->motor->conferir($pdf);

        $a = $resultado->assinaturas[0];
        self::assertSame('BELTRA DE TAL', $a->titular, 'o certificado foi mesmo trocado');
        self::assertTrue($a->chaveConfere, 'a chave pública é a mesma: só o vínculo pega a troca');
        self::assertSame(VereditoDaAssinatura::Alterada, $a->veredito);
        self::assertSame(ConferenciaDePdf::MOTIVO_CERTIFICADO_TROCADO, $a->motivo);
        self::assertSame(VinculoDoCertificado::Diferente, $a->vinculoDoCertificado);
        self::assertSame(SituacaoDaConferencia::Alterado, $resultado->situacao);
    }

    #[TestDox('certificado trocado no /Contents sem vínculo: a conta confere, mas titular e CPF ficam só declarados')]
    public function testCertificadoTrocadoSemVinculo(): void
    {
        $pdf = self::certificadoTrocado(OficinaDePdfAssinado::pdfAssinado());

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame('BELTRA DE TAL', $a->titular);
        self::assertSame(VereditoDaAssinatura::Integra, $a->veredito);
        self::assertSame(VinculoDoCertificado::NaoVinculado, $a->vinculoDoCertificado);
        self::assertStringContainsString('não estão vinculados', VinculoDoCertificado::NaoVinculado->motivo());
    }

    #[TestDox('resumo MD5: não conferida e sem os dados do certificado, como o protótipo')]
    public function testResumoNaoSuportado(): void
    {
        $chave = OficinaDePdfAssinado::chave();
        $pdf = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::pdfParaAssinar(),
            OficinaDePdfAssinado::cmsManual($chave, OficinaDePdfAssinado::certificado($chave), oidDoResumo: MontadorDeDer::OID_MD5),
        );

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::NaoConferida, $a->veredito);
        self::assertSame(LeitorDeAssinaturaCms::MOTIVO_RESUMO, $a->motivo);
        self::assertNull($a->titular);
        self::assertNull($a->algoritmoDoResumo);
    }

    #[TestDox('identificação pela chave (SubjectKeyIdentifier) acha o certificado certo mesmo com outro antes (M9)')]
    public function testIdentificacaoPelaChave(): void
    {
        $chave = OficinaDePdfAssinado::chave();
        $outro = OficinaDePdfAssinado::certificado(OficinaDePdfAssinado::chave('rsa2'), 'OUTRA PESSOA');
        $pdf = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::pdfParaAssinar(),
            OficinaDePdfAssinado::cmsManual($chave, OficinaDePdfAssinado::certificado($chave), pelaChave: true, certificadosAntes: [$outro]),
        );

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::Integra, $a->veredito);
        self::assertSame(OficinaDePdfAssinado::TITULAR, $a->titular);
    }

    #[TestDox('curva fora da lista do protótipo (secp256k1): não conferida')]
    public function testCurvaNaoSuportada(): void
    {
        $a = $this->motor->conferir(OficinaDePdfAssinado::pdfAssinado('k1'))->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::NaoConferida, $a->veredito);
        self::assertSame(VerificadorDeChave::MOTIVO_CURVA, $a->motivo);
        self::assertTrue($a->resumoConfere);
        self::assertNull($a->chaveConfere);
    }

    #[TestDox('carimbo do tempo do documento: não conferido (sem falso "alterada") e fora da conferência do CPF (M15)')]
    public function testCarimboDoTempo(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado();
        $pdf = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($primeira, '', 'ETSI.RFC3161'),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec'), OficinaDePdfAssinado::certificado(OficinaDePdfAssinado::chave('ec'), 'ACT DE TESTE')),
        );

        $resultado = $this->motor->conferir($pdf, null, OficinaDePdfAssinado::CPF);

        $carimbo = $resultado->assinaturas[1];
        self::assertSame(ConferenciaDePdf::TIPO_CARIMBO_DO_TEMPO, $carimbo->tipo);
        self::assertSame(VereditoDaAssinatura::NaoConferida, $carimbo->veredito);
        self::assertSame(ConferenciaDePdf::MOTIVO_CARIMBO_DO_TEMPO, $carimbo->motivo);
        self::assertNull($carimbo->resumoConfere);
        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame(ConferenciaDoCpf::Confere, $resultado->cpf);
    }

    #[TestDox('PDF sem assinatura')]
    public function testSemAssinatura(): void
    {
        $resultado = $this->motor->conferir(OficinaDePdfAssinado::pdfSemAssinatura());

        self::assertSame(SituacaoDaConferencia::SemAssinatura, $resultado->situacao);
        self::assertSame(ConferenciaDePdf::MOTIVO_SEM_ASSINATURA, $resultado->motivo);
        self::assertFalse($resultado->temAssinatura());
        self::assertNull($resultado->ultimaAssinatura());
        self::assertTrue($resultado->permiteCarimbo);
    }

    /** @return iterable<string, array{string}> */
    public static function cifras(): iterable
    {
        yield 'referência' => ['/Encrypt 9 0 R'];
        yield 'dicionário direto' => ['/Encrypt<</Filter/Standard>>'];
    }

    #[DataProvider('cifras')]
    #[TestDox('PDF cifrado ($_dataName) não permite carimbo')]
    public function testCifrado(string $trailer): void
    {
        $resultado = $this->motor->conferir(OficinaDePdfAssinado::pdfSemAssinatura($trailer));

        self::assertTrue($resultado->cifrado);
        self::assertFalse($resultado->permiteCarimbo);
    }

    /** @return iterable<string, array{string, string}> */
    public static function arquivosIlegiveis(): iterable
    {
        mt_srand(20261109);
        $lixo = '';
        for ($i = 0; $i < 5000; ++$i) {
            $lixo .= \chr(mt_rand(0, 255));
        }
        yield 'vazio' => ['', ConferenciaDePdf::MOTIVO_VAZIO];
        yield 'lixo' => [$lixo, ConferenciaDePdf::MOTIVO_NAO_E_PDF];
        yield 'texto com /ByteRange mas sem cabeçalho' => [str_repeat(' ', 1100) . '%PDF-1.7 /ByteRange [0 1 2 3]', ConferenciaDePdf::MOTIVO_NAO_E_PDF];
    }

    #[DataProvider('arquivosIlegiveis')]
    #[TestDox('arquivo $_dataName: não foi possível ler')]
    public function testArquivoIlegivel(string $arquivo, string $motivo): void
    {
        $resultado = $this->motor->conferir($arquivo);

        self::assertSame(SituacaoDaConferencia::Ilegivel, $resultado->situacao);
        self::assertSame($motivo, $resultado->motivo);
        self::assertSame([], $resultado->assinaturas);
        self::assertFalse($resultado->permiteCarimbo);
    }

    #[TestDox('limite: PDF com mais de 20 MB')]
    public function testLimiteDoTamanhoDoPdf(): void
    {
        $pdf = "%PDF-1.7\n" . str_repeat('0', LimitesDaConferencia::PDF_MAXIMO_EM_BYTES - 8);

        $resultado = $this->motor->conferir($pdf);

        self::assertSame(SituacaoDaConferencia::Ilegivel, $resultado->situacao);
        self::assertSame(ConferenciaDePdf::MOTIVO_GRANDE, $resultado->motivo);
        self::assertSame(LimitesDaConferencia::PDF_MAXIMO_EM_BYTES + 1, $resultado->tamanho);
    }

    #[TestDox('limite: mais de 30 faixas diferentes ou mais de 100 declarações de faixa')]
    public function testLimiteDeAssinaturas(): void
    {
        $faixas = static fn (int $quantidade, bool $iguais): string => "%PDF-1.7\n" . implode("\n", array_map(
            static fn (int $i): string => '/ByteRange [0 10 20 ' . ($iguais ? 1 : $i) . ']',
            range(1, $quantidade),
        ));

        $trinta = $this->motor->conferir($faixas(LimitesDaConferencia::ASSINATURAS_MAXIMAS, false));
        $trintaEUma = $this->motor->conferir($faixas(LimitesDaConferencia::ASSINATURAS_MAXIMAS + 1, false));
        $cemIguais = $this->motor->conferir($faixas(LimitesDaConferencia::DECLARACOES_DE_FAIXA_MAXIMAS, true));
        $centoEUmaIguais = $this->motor->conferir($faixas(LimitesDaConferencia::DECLARACOES_DE_FAIXA_MAXIMAS + 1, true));

        self::assertCount(30, $trinta->assinaturas);
        self::assertSame(SituacaoDaConferencia::Ilegivel, $trintaEUma->situacao);
        self::assertSame(ConferenciaDePdf::MOTIVO_ASSINATURAS_DEMAIS, $trintaEUma->motivo);
        self::assertCount(1, $cemIguais->assinaturas, 'faixas com os mesmos números contam uma vez');
        self::assertSame(SituacaoDaConferencia::Ilegivel, $centoEUmaIguais->situacao);
    }

    /** @return iterable<string, array{callable(int, int, int): string}> */
    public static function faixasInvalidas(): iterable
    {
        yield 'número com 13 dígitos' => [static fn (int $l, int $c, int $m): string => '0 1234567890123 ' . $c . ' ' . $m];
        yield 'não começa no zero' => [static fn (int $l, int $c, int $m): string => '1 ' . $l . ' ' . $c . ' ' . $m];
        yield 'segunda parte fora do arquivo' => [static fn (int $l, int $c, int $m): string => '0 ' . $l . ' ' . $c . ' ' . ($m + 1)];
        yield 'partes sobrepostas' => [static fn (int $l, int $c, int $m): string => '0 ' . ($c + 1) . ' ' . $c . ' ' . $m];
        yield 'trecho de fora com 1 byte' => [static fn (int $l, int $c, int $m): string => '0 ' . $l . ' ' . ($l + 1) . ' ' . ($m + $c - $l - 1)];
    }

    /** @param callable(int, int, int): string $numeros */
    #[DataProvider('faixasInvalidas')]
    #[TestDox('faixa inválida: $_dataName')]
    public function testFaixaInvalida(callable $numeros): void
    {
        [$pdf, $l, $c] = OficinaDePdfAssinado::preencherFaixa(OficinaDePdfAssinado::pdfParaAssinar());
        $m = \strlen($pdf) - $c;
        $pdf = OficinaDePdfAssinado::comFaixa(OficinaDePdfAssinado::pdfParaAssinar(), $numeros($l, $c, $m));

        $resultado = $this->motor->conferir($pdf);

        $a = $resultado->assinaturas[0];
        self::assertSame(VereditoDaAssinatura::Alterada, $a->veredito);
        self::assertSame(ConferenciaDePdf::MOTIVO_FAIXA_INVALIDA, $a->motivo);
        self::assertFalse($a->faixaValida);
        self::assertSame('', $a->tipo);
        self::assertSame(SituacaoDaConferencia::Alterado, $resultado->situacao);
    }

    #[TestDox('número absurdo da faixa vira -1 e a assinatura vai para o fim da lista')]
    public function testNumeroAbsurdo(): void
    {
        $pdf = OficinaDePdfAssinado::pdfAssinado() . "9 0 obj <</ByteRange [0 99999999999999999999 1 2]>> endobj\n";

        $resultado = $this->motor->conferir($pdf);

        self::assertSame([0, -1, 1, 2], $resultado->assinaturas[1]->faixaDeBytes);
        self::assertSame(-1, $resultado->assinaturas[1]->fim);
        self::assertSame(VereditoDaAssinatura::Integra, $resultado->assinaturas[0]->veredito);
    }

    #[TestDox('declaração de faixa fora do trecho que ela mesma assina (M4)')]
    public function testDeclaracaoForaDoTrecho(): void
    {
        $pdf = OficinaDePdfAssinado::pdfSemAssinatura() . "9 0 obj <</ByteRange [0 10 20 5]>> endobj\n";

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::Alterada, $a->veredito);
        self::assertSame(ConferenciaDePdf::MOTIVO_FAIXA_FORA_DO_TRECHO, $a->motivo);
    }

    /** @return iterable<string, array{string}> */
    public static function trechosNaoHexadecimais(): iterable
    {
        yield 'objeto escondido no espaço reservado (signature wrapping)' => [">\nendobj\n9 0 obj\n<</Type/Page>>\nendobj\n<"];
        yield 'letras fora do hexadecimal' => ['zz'];
    }

    #[DataProvider('trechosNaoHexadecimais')]
    #[TestDox('trecho fora da faixa com $_dataName: alterada, mesmo com a assinatura certa no começo (M4)')]
    public function testTrechoNaoHexadecimal(string $intruso): void
    {
        $pdf = OficinaDePdfAssinado::pdfAssinado();
        preg_match('/\/ByteRange \[0 \d+ (\d+)/', $pdf, $partes);
        $alterado = substr_replace($pdf, $intruso, (int) $partes[1] - 1 - \strlen($intruso) - 10, \strlen($intruso));

        $a = $this->motor->conferir($alterado)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::Alterada, $a->veredito);
        self::assertSame(ConferenciaDePdf::MOTIVO_TRECHO_NAO_HEXADECIMAL, $a->motivo);
    }

    #[TestDox('limite: trecho da assinatura com mais de 16 MB de hexadecimal não é conferido')]
    public function testLimiteDoTrechoDaAssinatura(): void
    {
        $reserva = intdiv(LimitesDaConferencia::TRECHO_DA_ASSINATURA_MAXIMO_EM_BYTES, 2) + 1;
        [$pdf] = OficinaDePdfAssinado::preencherFaixa(OficinaDePdfAssinado::pdfParaAssinar('', $reserva));

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::NaoConferida, $a->veredito);
        self::assertSame(ConferenciaDePdf::MOTIVO_ASSINATURA_GRANDE, $a->motivo);
        self::assertSame('PAdES', $a->tipo);
    }

    /** Lista de certificados revogados (CertificateList) sintética, com `$entradas` números de série. */
    private static function lcr(int $entradas): string
    {
        $d = MontadorDeDer::class;
        $revogados = '';
        for ($i = 0; $i < $entradas; ++$i) {
            $revogados .= $d::sequencia($d::tlv(0x02, pack('N', $i + 1)), $d::tlv(0x17, '261109120000Z'));
        }
        $corpo = $d::sequencia($d::inteiro(1), $d::algoritmo($d::OID_SHA256_COM_RSA), $d::sequencia(), $d::tlv(0x17, '261109120000Z'), $d::sequencia($revogados));

        return $d::sequencia($corpo, $d::algoritmo($d::OID_SHA256_COM_RSA), $d::tlv(0x03, "\0" . str_repeat("\x5A", 256)));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function revogacoesGrandes(): iterable
    {
        $d = MontadorDeDer::class;
        yield 'LCR de 150 mil entradas (3,4 MB) no campo crls' => [['lcrs' => self::lcr(150_000)]];
        yield 'LCR de 60 mil entradas no adbe-revocationInfoArchival' => [['atributosExtras' => $d::sequencia(
            $d::oid($d::OID_ARQUIVO_DE_REVOGACAO),
            $d::conjunto($d::sequencia($d::contexto(0, $d::sequencia(self::lcr(60_000))))),
        )]];
        yield 'atributo não assinado com 60 mil elementos' => [['naoAssinados' => $d::sequencia(
            $d::oid($d::OID_CARIMBO_DA_ASSINATURA),
            $d::conjunto($d::sequencia(str_repeat($d::nulo(), 60_000))),
        )]];
    }

    /** @param array<string, string> $extras */
    #[DataProvider('revogacoesGrandes')]
    #[TestDox('PDF com validação de longo prazo ($_dataName): os containers não lidos não contam no limite, e a assinatura confere')]
    public function testRevogacaoGrandeNaoAtrapalha(array $extras): void
    {
        $chave = OficinaDePdfAssinado::chave();
        $assinador = OficinaDePdfAssinado::cmsManual($chave, OficinaDePdfAssinado::certificado($chave), ...$extras);
        $tamanho = \strlen($assinador('x'));
        $pdf = OficinaDePdfAssinado::assinar(OficinaDePdfAssinado::pdfParaAssinar('', $tamanho + 1024), $assinador);

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::Integra, $a->veredito, $a->motivo);
        self::assertTrue($a->resumoConfere);
        self::assertTrue($a->chaveConfere);
    }

    #[TestDox('o limite de elementos continua valendo no que o motor lê: atributo assinado com 50 mil elementos')]
    public function testLimiteDeElementosNoQueELido(): void
    {
        $d = MontadorDeDer::class;
        $chave = OficinaDePdfAssinado::chave();
        $extra = $d::sequencia($d::oid($d::OID_CARIMBO_DA_ASSINATURA), $d::conjunto($d::sequencia(str_repeat($d::nulo(), 50_000))));
        $pdf = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::pdfParaAssinar('', 120_000),
            OficinaDePdfAssinado::cmsManual($chave, OficinaDePdfAssinado::certificado($chave), atributosExtras: $extra),
        );

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::NaoConferida, $a->veredito);
        self::assertSame(LimiteExcedido::MOTIVO_DO_LIMITE, $a->motivo);
    }

    /** @return iterable<string, array{string, string}> */
    public static function cmsMalformados(): iterable
    {
        $fundo = MontadorDeDer::nulo();
        for ($i = 0; $i < 40; ++$i) {
            $fundo = MontadorDeDer::sequencia($fundo);
        }
        yield 'DER com 41 níveis' => [$fundo, LimiteExcedido::MOTIVO_DO_LIMITE];
        yield 'DER com 50.001 elementos' => [MontadorDeDer::sequencia(str_repeat(MontadorDeDer::nulo(), 50_000)), LimiteExcedido::MOTIVO_DO_LIMITE];
        yield 'BER com tamanho indefinido' => ["\x30\x80\x05\x00\x00\x00", FormatoNaoReconhecido::MOTIVO];
        yield 'tamanho com 5 bytes' => ["\x30\x85\x00\x00\x00\x00\x02\x05\x00", FormatoNaoReconhecido::MOTIVO];
        yield 'elemento maior que o pai' => ["\x30\x03\x04\x09\x00", FormatoNaoReconhecido::MOTIVO];
        yield 'SEQUENCE vazia' => ["\x30\x00", FormatoNaoReconhecido::MOTIVO];
        yield 'não é SignedData' => [MontadorDeDer::sequencia(MontadorDeDer::oid(MontadorDeDer::OID_DADOS), MontadorDeDer::contexto(0, MontadorDeDer::sequencia())), FormatoNaoReconhecido::MOTIVO];
    }

    #[DataProvider('cmsMalformados')]
    #[TestDox('CMS $_dataName: não conferida, sem exceção')]
    public function testCmsMalformado(string $der, string $motivo): void
    {
        $reserva = max(OficinaDePdfAssinado::RESERVA, \strlen($der) + 16);
        $pdf = OficinaDePdfAssinado::assinar(OficinaDePdfAssinado::pdfParaAssinar('', $reserva), static fn (): string => $der);

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::NaoConferida, $a->veredito);
        self::assertSame($motivo, $a->motivo);
    }

    #[TestDox('limite: mais de 30 certificados no CMS')]
    public function testLimiteDeCertificados(): void
    {
        $chave = OficinaDePdfAssinado::chave();
        $outro = OficinaDePdfAssinado::certificado(OficinaDePdfAssinado::chave('rsa2'), 'OUTRA PESSOA');
        $pdf = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::pdfParaAssinar('', 40_000),
            OficinaDePdfAssinado::cmsManual($chave, OficinaDePdfAssinado::certificado($chave), certificadosAntes: array_fill(0, LimitesDaConferencia::CERTIFICADOS_MAXIMOS, $outro)),
        );

        $a = $this->motor->conferir($pdf)->assinaturas[0];

        self::assertSame(VereditoDaAssinatura::NaoConferida, $a->veredito);
        self::assertSame(LimiteExcedido::MOTIVO_DO_LIMITE, $a->motivo);
    }

    /** @return iterable<string, array{int}> */
    public static function cortes(): iterable
    {
        foreach ([1, 10, 100, 700, 5000, 9000, -1] as $corte) {
            yield 'cortado em ' . $corte => [$corte];
        }
    }

    #[DataProvider('cortes')]
    #[TestDox('PDF assinado $_dataName: nenhuma exceção e nada íntegro')]
    public function testPdfTruncado(int $corte): void
    {
        $resultado = $this->motor->conferir(substr(OficinaDePdfAssinado::pdfAssinado(), 0, $corte));

        self::assertNotSame(SituacaoDaConferencia::Integro, $resultado->situacao);
    }

    #[TestDox('300 mutações aleatórias no CMS e no arquivo: nenhuma exceção, e o motivo sempre preenchido')]
    public function testMutacoesAleatorias(): void
    {
        $pdf = OficinaDePdfAssinado::pdfAssinado();
        preg_match('/\/ByteRange \[0 (\d+) (\d+)/', $pdf, $partes);
        $l = (int) $partes[1];
        $tamanhoDoCms = MontadorDeDer::tamanhoTotal((string) hex2bin(substr($pdf, $l + 1, 64)) . str_repeat("\0", 32));
        mt_srand(4242);

        for ($i = 0; $i < 300; ++$i) {
            $mutado = $pdf;
            if ($i % 2 === 0) {
                $mutado[$l + 1 + mt_rand(0, 2 * $tamanhoDoCms - 1)] = '0123456789abcdef'[mt_rand(0, 15)];
            } else {
                $mutado[mt_rand(0, \strlen($pdf) - 1)] = \chr(mt_rand(0, 255));
            }

            $resultado = $this->motor->conferir($mutado);

            self::assertNotSame('', $resultado->motivo);
            foreach ($resultado->assinaturas as $assinatura) {
                self::assertNotSame('', $assinatura->motivo);
            }
        }
    }

    private static function trocarUltimoByte(string $der): string
    {
        $der[\strlen($der) - 1] = \chr(\ord($der[\strlen($der) - 1]) ^ 0x01);

        return $der;
    }
}
