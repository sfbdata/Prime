<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Selo;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoAcrescimo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoPrefixo;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VereditoDaAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VinculoDoCertificado;
use App\AssinaturaEletronica\Service\Selo\HttpSeladorDePdf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Ida e volta do selo pelo motor PHP (spec assinatura-documentos-etapa-8, E8-6). Os PDFs de `Fixtures/Selo` saem do
 * próprio validador, com o certificado de TESTE ("AC Raiz Teste BlueJus" > "AC Teste e-CNPJ" > a folha com o CNPJ):
 *
 *     docker run --rm --network none --user "$(id -u):$(id -g)" -v <app>/tests/AssinaturaEletronica/Fixtures/Selo:/saida \
 *         bluejus-validador-pdf:teste python -m testes.exemplo_selado /saida
 *
 * (o script grava também o PEM da raiz de teste, que fica de fora: `*.pem` não entra no git, pelo `.gitignore` e pelo
 * `verificar-segredos`).
 *
 * O motor confere a integridade, o vínculo do certificado, o prefixo e o CNPJ que o certificado declara; a cadeia até
 * a raiz não é dele (M25, é do `/v1/conferir`). Para não ficar sem prova, o elo folha > AC de teste é conferido aqui à
 * parte, com o OpenSSL e os certificados que o próprio CMS do selo carrega. O elo AC > raiz pede o certificado da raiz,
 * que não está no repositório: aqui só se confere que a AC declara a raiz de teste como emissora.
 *
 * Limite medido: com carimbo do tempo (ACT configurada), o validador grava o DSS (B-LT) num acréscimo depois do selo.
 * O motor não lê esse acréscimo e responde `nao_conferido` ("acréscimos depois da última assinatura"), embora o selo
 * esteja íntegro e cubra a revisão inteira. Sem carimbo (B-B, o que produção faz enquanto não houver ACT), sai
 * `integro`.
 */
#[CoversClass(ConferenciaDePdf::class)]
final class SeloPeloMotorTest extends TestCase
{
    private const PASTA = __DIR__ . '/../../Fixtures/Selo/';
    private const ORIGINAL = 'pdf-selado-teste-original.pdf';
    private const SEM_CARIMBO = 'pdf-selado-teste.pdf';
    private const COM_CARIMBO = 'pdf-selado-teste-com-carimbo.pdf';
    private const RAIZ = 'AC Raiz Teste BlueJus';

    private const CNPJ = '11222333000181';
    private const RAZAO_SOCIAL = 'BLUEJUS TECNOLOGIA LTDA';
    private const AC = 'AC Teste e-CNPJ';

    /** @return iterable<string, array{string}> */
    public static function selados(): iterable
    {
        yield 'sem carimbo (PAdES B-B)' => [self::SEM_CARIMBO];
        yield 'com carimbo e DSS (B-LT)' => [self::COM_CARIMBO];
    }

    #[DataProvider('selados')]
    #[TestDox('$_dataName: o selo é a única assinatura nova, íntegra, do certificado que assinou, sobre o original intacto')]
    public function testSeloIntegroSobreOOriginal(string $arquivo): void
    {
        $original = self::ler(self::ORIGINAL);
        $selado = self::ler($arquivo);

        self::assertGreaterThan(\strlen($original), \strlen($selado));
        self::assertStringStartsWith($original, $selado, 'acréscimo incremental (E8-1)');

        $resultado = (new ConferenciaDePdf())->conferir($selado, $original);

        self::assertSame(ConferenciaDoPrefixo::Confere, $resultado->prefixo);
        self::assertSame(1, $resultado->assinaturasNovas);
        self::assertCount(1, $resultado->assinaturas);
        $selo = $resultado->assinaturas[0];
        self::assertSame('PAdES', $selo->tipo);
        self::assertSame(VereditoDaAssinatura::Integra, $selo->veredito);
        self::assertTrue($selo->resumoConfere);
        self::assertTrue($selo->chaveConfere);
        self::assertSame(VinculoDoCertificado::Vinculado, $selo->vinculoDoCertificado);
        self::assertSame(ConferenciaDoAcrescimo::SoAssinatura, $selo->acrescimo, 'o acréscimo do selo só traz a assinatura');
        self::assertGreaterThan(\strlen($original), $selo->fim);
    }

    #[DataProvider('selados')]
    #[TestDox('$_dataName: o motor lê o CNPJ e a razão social do e-CNPJ de teste e o emissor; o responsável ele não expõe')]
    public function testCertificadoDoSelo(string $arquivo): void
    {
        $selo = (new ConferenciaDePdf())->conferir(self::ler($arquivo))->assinaturas[0];

        self::assertSame(self::CNPJ, $selo->cnpj);
        self::assertSame(self::RAZAO_SOCIAL, $selo->titular);
        self::assertSame(self::AC, $selo->emissor);
        self::assertSame('11.222.333/0001-81', $selo->documentoMascarado);
        // O CPF do responsável (2.16.76.1.3.4) não é lido pelo motor: ele só lê o 2.16.76.1.3.1 do e-CPF.
        self::assertNull($selo->cpf);
    }

    #[TestDox('sem carimbo: o selo cobre o arquivo inteiro e o motor dá o PDF selado como íntegro')]
    public function testSemCarimboIntegro(): void
    {
        $selado = self::ler(self::SEM_CARIMBO);

        $resultado = (new ConferenciaDePdf())->conferir($selado, self::ler(self::ORIGINAL));

        self::assertSame(SituacaoDaConferencia::Integro, $resultado->situacao);
        self::assertTrue($resultado->assinaturas[0]->cobreODocumento);
        self::assertSame(0, $resultado->assinaturas[0]->bytesDepois);
        self::assertSame(\strlen($selado), $resultado->assinaturas[0]->fim);
    }

    #[TestDox('com carimbo: o DSS vem num acréscimo depois do selo, que o motor não lê (nao_conferido); o selo segue íntegro')]
    public function testComCarimboDssDepoisDoSelo(): void
    {
        $selado = self::ler(self::COM_CARIMBO);

        $resultado = (new ConferenciaDePdf())->conferir($selado, self::ler(self::ORIGINAL));
        $selo = $resultado->assinaturas[0];

        self::assertSame(SituacaoDaConferencia::NaoConferido, $resultado->situacao);
        self::assertSame(ConferenciaDePdf::MOTIVO_ACRESCIMO, $resultado->motivo);
        self::assertSame(VereditoDaAssinatura::Integra, $selo->veredito);
        self::assertFalse($selo->cobreODocumento);
        self::assertSame(\strlen($selado) - $selo->fim, $selo->bytesDepois);
        self::assertStringContainsString('/DSS', substr($selado, $selo->fim), 'o que vem depois do selo é o DSS do B-LT');
    }

    #[DataProvider('selados')]
    #[TestDox('$_dataName: o CMS do selo leva a folha e a AC de teste, e a folha foi emitida pela AC (OpenSSL, fora do motor)')]
    public function testCadeiaDoSeloAteAAcDeTeste(string $arquivo): void
    {
        $certificados = self::certificadosDoSelo(self::ler($arquivo));

        self::assertEqualsCanonicalizing([self::AC, self::RAZAO_SOCIAL . ':' . self::CNPJ], array_keys($certificados));
        self::assertSame(1, openssl_x509_verify($certificados[self::RAZAO_SOCIAL . ':' . self::CNPJ], $certificados[self::AC]), 'a folha foi emitida pela AC');
        self::assertSame(self::RAIZ, openssl_x509_parse($certificados[self::AC])['issuer']['CN'] ?? null, 'a AC declara a raiz de teste como emissora');
    }

    #[DataProvider('selados')]
    #[TestDox('$_dataName: o cliente HTTP aceita o selado de verdade (começa com o original e cresce)')]
    public function testClienteAceitaOSeladoDeVerdade(string $arquivo): void
    {
        $selado = self::ler($arquivo);
        $http = new MockHttpClient(new MockResponse($selado, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/pdf']]));

        $devolvido = (new HttpSeladorDePdf($http, new LogDoSelo(), 'http://validador_pdf:8080', 'token-inventado-do-teste'))
            ->selar(self::ler(self::ORIGINAL));

        self::assertSame($selado, $devolvido);
    }

    private static function ler(string $arquivo): string
    {
        $conteudo = file_get_contents(self::PASTA . $arquivo);
        self::assertIsString($conteudo, $arquivo);

        return $conteudo;
    }

    /**
     * Os certificados que o CMS do selo carrega, pelo CN do titular: o `/Contents` da faixa de bytes, sem o preenchimento
     * de zeros do fim, lido pelo OpenSSL.
     *
     * @return array<string, string> CN => PEM
     */
    private static function certificadosDoSelo(string $pdf): array
    {
        self::assertSame(1, preg_match('/\/ByteRange\s*\[\s*0\s+(\d+)\s+(\d+)\s+\d+\s*\]/', $pdf, $faixa));
        $der = (string) hex2bin(trim(substr($pdf, (int) $faixa[1], (int) $faixa[2] - (int) $faixa[1]), '<>'));
        // O CMS tem centenas de bytes: o tamanho do DER vem na forma longa (0x8N e N bytes).
        self::assertGreaterThanOrEqual(0x81, \ord($der[1]));
        $digitos = \ord($der[1]) & 0x7F;
        $tamanho = 0;
        for ($i = 0; $i < $digitos; ++$i) {
            $tamanho = $tamanho * 256 + \ord($der[2 + $i]);
        }
        $pem = "-----BEGIN PKCS7-----\n" . chunk_split(base64_encode(substr($der, 0, 2 + $digitos + $tamanho)), 64, "\n") . "-----END PKCS7-----\n";
        self::assertTrue(openssl_pkcs7_read($pem, $certificados));

        $porNome = [];
        foreach ($certificados as $certificado) {
            $porNome[(string) (openssl_x509_parse($certificado)['subject']['CN'] ?? '')] = $certificado;
        }

        return $porNome;
    }
}
