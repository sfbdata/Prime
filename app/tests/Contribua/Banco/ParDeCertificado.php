<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Banco;

/** Gera um certificado autoassinado e a chave dele, em PEM, para os testes (nunca um segredo real). */
final class ParDeCertificado
{
    /** @return array{certificado: string, chave: string} */
    public static function novo(): array
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'teste.bluejus'], $chave);
        $x509 = openssl_csr_sign($csr, null, $chave, 1);
        openssl_x509_export($x509, $certificado);
        openssl_pkey_export($chave, $chavePem);

        return ['certificado' => $certificado, 'chave' => $chavePem];
    }
}
