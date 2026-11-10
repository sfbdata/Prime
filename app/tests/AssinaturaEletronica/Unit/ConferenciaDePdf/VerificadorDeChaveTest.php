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

    /**
     * Vetores do mesmo comando com chaves de 1025 e 2047 bits (`openssl genpkey -pkeyopt rsa_keygen_bits:N`). Com 1025
     * bits o bloco aberto tem um byte a mais que o EM (emLen = k - 1); com 2047, os dois bits de cima do EM precisam
     * ser zero. Os vetores `*_BYTE_ALTO` são o EM válido de cada um com o byte de cima trocado (01 no bloco de 1025 e o
     * bit 0x40 ligado no de 2047), assinado em modo cru (`openssl pkeyutl -decrypt -pkeyopt rsa_padding_mode:none`):
     * sem as conferências do topo, eles passariam.
     */
    private const PSS_1025_CHAVE = 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQGuwqxEoAoWJ3xWd2hNC1ripf4ShqnTgRR5WhXZ8rwdZKDi3P9+xp9Zyx078PdSEj6WFZNKmuWv4ee5N4Ouv2HU3zDm0kaYMEagOsyLt0oR8LZck/hZNHi0Yb8z0I/oAvA8tFktnITcwMSAJefEqpeWEgHCDscUQSa9wFlFkb1rawIDAQAB';
    private const PSS_1025_ASSINATURA = 'AXRSMrC8dlTlRxECZMX+2ywAyyIlmLiRt07xQMTdsI2F0joYlFp+xklpfHZQT7ZQyfBoCgjxDeGHEe/jWE0BYZ3ZXrKK2dcrG24nRSbU9YKdpJPAwNSKHqz/fdKX6YruAELL6eSnLWI0wlO7L5jW9KoWwvFAHUKDFhHJhH1BpWUb';
    private const PSS_1025_BYTE_ALTO = 'AUFzDx+dGk2PxcMb+hIsRNVG25C2yeTHc4vvatm857MOkFgIQtY4cSf0F+jREaAdFVnwGMC8obs/6UonMoW/SciVvqY6KZtJlQCeXJIIt2xEXCc0acy/+BA3klj3pDjGfPHJZJfcXmIjs5BohX5xkoexqTAyhY3PRtKcC/yKWw5J';
    private const PSS_2047_CHAVE = 'MIIBITANBgkqhkiG9w0BAQEFAAOCAQ4AMIIBCQKCAQBZpIGH/3T+h8ARfypua+ae+naIkic574hr1/0qrv+UEut8nvDAFzGQJJ5LzdVJgchbARS2XNVnbxVrFxtcfmLBN6Ny9GVpqbniT3X717ScF62jOck0qBif1wL0twR7oJNt3OwdkUCb5lMfsjttLLz071TaWZjfT3qJgDHluWyfR9pBl1HD0z6HjCUanvS9zwcyAhTDAYOLfqTsBPuyPyz3UdRmqKr5HbJA4nGJW4xzf3XMYk3t4txDTI1sRmcy9siafQMPtkKOISwglBVxLsGHACII4EA89ZETgC33coxT+EpYHpoKlXdcqRNbdJ/l4IKzIpygOVVG4PMz3sT604arAgMBAAE=';
    private const PSS_2047_ASSINATURA = 'LieHwi4e9UeUJAzbnyjmA0SNYadThkeCX1hLcc0tUKRpe4ffkWqWdMSxRkcKxwd2XSWh9rTXmIJgPFAhMAhnBff2so9nIEh5wPmQ8RhmdL90haUj2ZtBG7Gl7NGDJ6UxTz/0nUmlGz9YdRC1kOyt0IHkXSng3Ff0G2NNfpFqBHRNWvFbSBfwBsx6+4g2aCCwvXXHySywWX7dQbGrdRjwZ/l69ev/K74sfIMTKjSXkKaEC/BfJM75ZXJOtRPH1PaFZs3fYRMLtSsSbVbzWGDLhHV9GJqB1wRQxG3DHvBw6rWSTv9gZV7tnmrk486Pd2L7vRdXLd7avux6plEObE0NRA==';
    private const PSS_2047_BYTE_ALTO = 'MSavSyFbp/6XvTZCZj/J96Us17PRNyBpSDKAuxLWyDg7cuYQ5gUCv8bbMNv87pajN9+biTcKYju+zP04OEpONRUKW0bvuxiAXCn0IXCj+56h91TePPcJMQ69I3Y/pEEvh+kwBKCoG4gmputPfMxKMyizvp25UH1jqqd2sc813DGSEW/SVqWmgoJxj2zW6yKIMD/LS6Aec8KwChU/RuDIu39p9semMRVElGxMhiAChzVkUDycJEy9FqTgozeKQw9dB60QQAK4GjVsXD/Y4hSMz3jJSNfhEVAS3iuZ5InfaSVcW9sn5V27/3O7VwQj5KllLoueV0LWYVgwxggqG1X4Uw==';

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

    #[TestDox('RSA-PSS com chave de 1025 e 2047 bits: o vetor do OpenSSL confere e o byte de cima trocado não')]
    public function testPssComChaveForaDoMultiploDeOito(): void
    {
        $chave1025 = (string) base64_decode(self::PSS_1025_CHAVE, true);
        $chave2047 = (string) base64_decode(self::PSS_2047_CHAVE, true);

        self::assertTrue($this->verificador->pss(self::PSS_MENSAGEM, (string) base64_decode(self::PSS_1025_ASSINATURA, true), $chave1025, 'SHA-256')->confere);
        self::assertTrue($this->verificador->pss(self::PSS_MENSAGEM, (string) base64_decode(self::PSS_2047_ASSINATURA, true), $chave2047, 'SHA-256')->confere);
        self::assertFalse($this->verificador->pss(self::PSS_MENSAGEM, (string) base64_decode(self::PSS_1025_BYTE_ALTO, true), $chave1025, 'SHA-256')->confere, 'byte a mais do bloco aberto diferente de zero');
        self::assertFalse($this->verificador->pss(self::PSS_MENSAGEM, (string) base64_decode(self::PSS_2047_BYTE_ALTO, true), $chave2047, 'SHA-256')->confere, 'bit acima de emBits ligado');
        self::assertFalse($this->verificador->pss(self::PSS_MENSAGEM . '.', (string) base64_decode(self::PSS_2047_ASSINATURA, true), $chave2047, 'SHA-256')->confere);
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

    #[TestDox('ECDSA: só SEQUENCE com exatamente dois INTEGER; inteiro a mais, etiqueta trocada ou SET não conferem')]
    public function testEcdsaComEstruturaErrada(): void
    {
        $chave = OficinaDePdfAssinado::chave('ec');
        openssl_sign('mensagem', $assinatura, $chave, \OPENSSL_ALGO_SHA256);
        [$r, $s] = MontadorDeDer::partes((string) $assinatura);
        $spki = self::spki($chave);
        $conferir = fn (string $der): ?bool => $this->verificador->verificar(self::OID_ECDSA_SHA256, 'SHA-256', 'mensagem', $der, $spki)->confere;

        self::assertTrue($conferir(MontadorDeDer::sequencia($r, $s)), 'a assinatura remontada continua válida');
        self::assertNull($conferir(MontadorDeDer::sequencia($r, $s, MontadorDeDer::inteiro(1))), 'três elementos');
        self::assertNull($conferir(MontadorDeDer::sequencia($r)), 'um elemento');
        self::assertNull($conferir(MontadorDeDer::sequencia("\x04" . substr($r, 1), $s)), 'r como OCTET STRING');
        self::assertNull($conferir(MontadorDeDer::sequencia($r, "\x04" . substr($s, 1))), 's como OCTET STRING');
        self::assertNull($conferir(MontadorDeDer::conjunto($r, $s)), 'SET no lugar de SEQUENCE');
    }

    #[TestDox('RSA-PSS com chave de menos de 512 bits: não conferido, com o motivo de chave curta')]
    public function testPssChaveCurta(): void
    {
        $modulo = "\xC0" . str_repeat("\x5A", 30) . "\x01";
        $rsa = MontadorDeDer::sequencia(MontadorDeDer::tlv(0x02, "\0" . $modulo), MontadorDeDer::inteiro(65537));
        $spki = MontadorDeDer::sequencia(MontadorDeDer::algoritmo(MontadorDeDer::OID_RSA, MontadorDeDer::nulo()), MontadorDeDer::tlv(0x03, "\0" . $rsa));

        $resultado = $this->verificador->pss(self::PSS_MENSAGEM, str_repeat("\x01", 32), $spki, 'SHA-256');

        self::assertNull($resultado->confere);
        self::assertSame(VerificadorDeChave::MOTIVO_CHAVE_CURTA, $resultado->motivo);
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
