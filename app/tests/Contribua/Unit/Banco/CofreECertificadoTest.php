<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Banco;

use App\Contribua\Banco\CertificadoDoBanco;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\FalhaNoBanco;
use App\Tests\Contribua\Banco\ParDeCertificado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CofreDeCertificado::class)]
#[CoversClass(CertificadoDoBanco::class)]
final class CofreECertificadoTest extends TestCase
{
    #[TestDox('O cofre cifra e decifra; o texto guardado não contém o original; adulterado ou com outra chave, recusa')]
    public function testCofre(): void
    {
        $cofre = new CofreDeCertificado(base64_encode(random_bytes(32)));
        $cifrado = $cofre->cifrar('conteúdo de teste do certificado');

        self::assertStringNotContainsString('certificado', (string) base64_decode($cifrado));
        self::assertSame('conteúdo de teste do certificado', $cofre->decifrar($cifrado));
        $this->expectException(FalhaNoBanco::class);
        (new CofreDeCertificado(base64_encode(random_bytes(32))))->decifrar($cifrado);
    }

    #[TestDox('Sem a chave de cifra no servidor, o cofre recusa com mensagem clara')]
    public function testSemChave(): void
    {
        $this->expectExceptionMessage('CONTRIBUA_CRYPTO_KEY');
        (new CofreDeCertificado(''))->cifrar('x');
    }

    #[TestDox('Lê o par .pem + .key; recusa chave de outro certificado; recusa quando falta um dos dois')]
    public function testCertificado(): void
    {
        $par = ParDeCertificado::novo();
        $outro = ParDeCertificado::novo();
        $sut = new CertificadoDoBanco();

        self::assertSame($par, $sut->ler(['cert.pem' => $par['certificado'], 'chave.key' => $par['chave']]));
        $this->assertRecusa(fn () => $sut->ler(['cert.pem' => $par['certificado'], 'chave.key' => $outro['chave']]), 'não é a deste certificado');
        $this->assertRecusa(fn () => $sut->ler(['cert.pem' => $par['certificado']]), 'Envie o certificado e a chave');
    }

    #[TestDox('Lê o .p12 sem senha e devolve o par em PEM')]
    public function testPkcs12(): void
    {
        $par = ParDeCertificado::novo();
        openssl_pkcs12_export($par['certificado'], $p12, $par['chave'], '');

        $lido = (new CertificadoDoBanco())->ler(['cora.p12' => $p12]);

        self::assertTrue(openssl_x509_check_private_key($lido['certificado'], $lido['chave']));
    }

    private function assertRecusa(callable $acao, string $trecho): void
    {
        try {
            $acao();
            self::fail('Devia recusar.');
        } catch (\DomainException $e) {
            self::assertStringContainsString($trecho, $e->getMessage());
        }
    }
}
