<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaChave;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SemAvisos;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\VerificadorDeChave;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerificadorDeChave::class)]
#[CoversClass(ResultadoDaChave::class)]
#[CoversClass(SemAvisos::class)]
final class VerificadorDeChaveTest extends TestCase
{
    /**
     * Vetor gerado pelo `openssl` de linha de comando (3.5.7), independente do motor:
     * `openssl dgst -sha256 -sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:digest -sign k.pem msg`.
     */
    private const PSS_CHAVE = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtAwNicD7uYLADOVdfJgObzz5T57jwpx1+A+Vuxs4mkNcPqhYJqx7Sx1g3897RSybLEeUtRSowhLYdx+4tGQsTA9xYAMQh58cmRiB+HGL1Qn9QDk9uxynSre1t89jpJbFT4JZwjrDETb4AO8C6IKAl8+qnUaS+ZSXoExSKda4k5ovhvSJmanwa595Mh2dPiT1AJiP+GBfk0sFTCOe55JDw+GY62JkujQxJqAArxXk5KNnAR+d1oAqFuqQWS1BZ2uqeeTsm4Y6+NN83pztck/Yh+tWc49DYIyeL2HFkyKpraxQJEWsXONzjuHh5E9k5UNOea0zMd8fKgHg4bADzm4DrwIDAQAB';
    private const PSS_MENSAGEM = 'BlueJus confere RSA-PSS';
    private const PSS_ASSINATURA = 'IxHyLvaAnUveqKwsye4hxU+vqJSKXSWZeLxrbbEiusU3tBXjCfm0S2E4G/bWdyfCsFbq2yU4yON4gZvg7e8DAWwVqlBab0FAQOJMJyq1EvKE9GGXAFb0Yx8U0OqRVMxfYgD/jUzOYV+pOoThn1uisBpA1imhf5idTK8rCn00KpfXNPFM+WWqWovl97VSpxfJHr2gYMHfXcpqvXT8BQDB3ct4icZUxUrE396jWM+dcpfgpT9hJcboOVeXRu97EAlthWQSG1BuHvlyZFtzjUU+OYVjs4gan38QZwyM8+nutHIQCBtoR5AXl9MhFbcDdxRW/8M8JvFDCAVXA+v+f7hAag==';

    private const OID_PSS = '2a864886f70d01010a';
    private const OID_RSA = '2a864886f70d010101';
    private const OID_ECDSA_SHA256 = '2a8648ce3d040302';

    private VerificadorDeChave $verificador;

    protected function setUp(): void
    {
        $this->verificador = new VerificadorDeChave();
    }

    #[TestDox('RSA-PSS: o vetor do OpenSSL confere')]
    public function testPssDoOpensslConfere(): void
    {
        $resultado = $this->verificador->verificar(self::OID_PSS, 'SHA-256', self::PSS_MENSAGEM, self::assinaturaPss(), self::chavePss());

        self::assertTrue($resultado->confere);
    }

    #[TestDox('RSA-PSS: mensagem trocada, assinatura curta, assinatura maior que o módulo e hash diferente não conferem')]
    public function testPssNaoConfere(): void
    {
        $assinatura = self::assinaturaPss();

        self::assertFalse($this->verificador->pss(self::PSS_MENSAGEM . '.', $assinatura, self::chavePss(), 'SHA-256')->confere);
        self::assertFalse($this->verificador->pss(self::PSS_MENSAGEM, substr($assinatura, 1), self::chavePss(), 'SHA-256')->confere);
        self::assertFalse($this->verificador->pss(self::PSS_MENSAGEM, str_repeat("\xFF", 256), self::chavePss(), 'SHA-256')->confere);
        self::assertFalse($this->verificador->pss(self::PSS_MENSAGEM, $assinatura, self::chavePss(), 'SHA-512')->confere, 'sal de 64 bytes não é o do vetor');
    }

    #[TestDox('RSA-PSS com chave EC, hash desconhecido ou chave ilegível: não conferido')]
    public function testPssNaoConferido(): void
    {
        $chaveEc = self::spki(OficinaDePdfAssinado::chave('ec'));

        self::assertNull($this->verificador->pss(self::PSS_MENSAGEM, self::assinaturaPss(), $chaveEc, 'SHA-256')->confere);
        self::assertNull($this->verificador->pss(self::PSS_MENSAGEM, self::assinaturaPss(), self::chavePss(), 'MD5')->confere);
        self::assertSame(VerificadorDeChave::MOTIVO_CHAVE, $this->verificador->pss(self::PSS_MENSAGEM, self::assinaturaPss(), 'lixo', 'SHA-256')->motivo);
    }

    #[TestDox('PKCS#1 v1.5 e ECDSA conferem pelo openssl_verify; tipo de chave trocado não é conferido')]
    public function testPkcs1EEcdsa(): void
    {
        $rsa = OficinaDePdfAssinado::chave('rsa');
        $ec = OficinaDePdfAssinado::chave('ec');
        openssl_sign('mensagem', $assinaturaRsa, $rsa, \OPENSSL_ALGO_SHA256);
        openssl_sign('mensagem', $assinaturaEc, $ec, \OPENSSL_ALGO_SHA256);

        self::assertTrue($this->verificador->verificar(self::OID_RSA, 'SHA-256', 'mensagem', (string) $assinaturaRsa, self::spki($rsa))->confere);
        self::assertFalse($this->verificador->verificar(self::OID_RSA, 'SHA-256', 'outra', (string) $assinaturaRsa, self::spki($rsa))->confere);
        self::assertTrue($this->verificador->verificar(self::OID_ECDSA_SHA256, 'SHA-1', 'mensagem', (string) $assinaturaEc, self::spki($ec))->confere);
        self::assertFalse($this->verificador->verificar(self::OID_ECDSA_SHA256, 'SHA-256', 'outra', (string) $assinaturaEc, self::spki($ec))->confere);
        self::assertNull($this->verificador->verificar(self::OID_RSA, 'SHA-256', 'mensagem', (string) $assinaturaRsa, self::spki($ec))->confere);
    }

    #[TestDox('ECDSA com assinatura que não é DER ou com inteiro maior que a curva: não conferido, como o protótipo')]
    public function testEcdsaMalformada(): void
    {
        $ec = self::spki(OficinaDePdfAssinado::chave('ec'));
        $grande = MontadorDeDer::sequencia(MontadorDeDer::tlv(0x02, str_repeat("\x01", 33)), MontadorDeDer::tlv(0x02, "\x01"));

        self::assertNull($this->verificador->verificar(self::OID_ECDSA_SHA256, 'SHA-256', 'mensagem', 'não é DER', $ec)->confere);
        self::assertNull($this->verificador->verificar(self::OID_ECDSA_SHA256, 'SHA-256', 'mensagem', $grande, $ec)->confere);
    }

    #[TestDox('algoritmo de assinatura fora da lista do protótipo (Ed25519): não conferido')]
    public function testAlgoritmoNaoSuportado(): void
    {
        $resultado = $this->verificador->verificar('2b6570', 'SHA-256', 'mensagem', 'x', self::chavePss());

        self::assertNull($resultado->confere);
        self::assertSame(VerificadorDeChave::MOTIVO_ALGORITMO, $resultado->motivo);
    }

    private static function chavePss(): string
    {
        return (string) base64_decode(self::PSS_CHAVE, true);
    }

    private static function assinaturaPss(): string
    {
        return (string) base64_decode(self::PSS_ASSINATURA, true);
    }

    private static function spki(\OpenSSLAsymmetricKey $chave): string
    {
        $pem = (string) (openssl_pkey_get_details($chave) ?: [])['key'];

        return (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem), true);
    }
}
