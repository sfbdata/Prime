<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Banco;

use App\Contribua\Banco\CertificadoDoBanco;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Entity\IntegracaoBancaria;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Contribua\UseCase\EnviarCertificadoDoBancoUseCase;
use App\Entity\Auth\User;
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

    #[TestDox('Lê o .pfx com senha (D7 da C4b): com a senha certa abre; sem senha ou com a errada, recusa pedindo a senha')]
    public function testPfxComSenha(): void
    {
        $par = ParDeCertificado::novo();
        openssl_pkcs12_export($par['certificado'], $pfx, $par['chave'], 'senha-do-a1');
        $sut = new CertificadoDoBanco();

        $lido = $sut->ler(['empresa.pfx' => $pfx], 'senha-do-a1');

        self::assertTrue(openssl_x509_check_private_key($lido['certificado'], $lido['chave']));
        self::assertSame(openssl_x509_fingerprint($par['certificado']), openssl_x509_fingerprint($lido['certificado']));
        $this->assertRecusa(fn () => $sut->ler(['empresa.pfx' => $pfx]), 'informe a senha do certificado');
        $this->assertRecusa(fn () => $sut->ler(['empresa.pfx' => $pfx], 'errada'), 'senha incorreta');
    }

    #[TestDox('A senha do .pfx não fica em lugar nenhum: o caso de uso guarda só o par cifrado')]
    public function testSenhaNaoGravada(): void
    {
        $par = ParDeCertificado::novo();
        openssl_pkcs12_export($par['certificado'], $pfx, $par['chave'], 'senha-do-a1');
        $cofre = new CofreDeCertificado(base64_encode(random_bytes(32)));
        $integracao = new IntegracaoBancaria();
        $repo = $this->createStub(IntegracaoBancariaRepository::class);
        $repo->method('atual')->willReturn($integracao);

        $nomes = (new EnviarCertificadoDoBancoUseCase(new CertificadoDoBanco(), $cofre, $repo))->executar(['empresa.pfx' => $pfx], new User(), 'senha-do-a1');

        self::assertSame(['empresa.pfx'], $nomes);
        self::assertTrue($integracao->temCertificado());
        $guardado = serialize($integracao);
        self::assertStringNotContainsString('senha-do-a1', $guardado);
        self::assertStringNotContainsString('senha-do-a1', $cofre->decifrar((string) $integracao->getCertificadoCifrado()) . $cofre->decifrar((string) $integracao->getChaveCifrada()));
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
