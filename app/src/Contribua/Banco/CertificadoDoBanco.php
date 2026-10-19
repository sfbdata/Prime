<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

/**
 * Lê o certificado e a chave privada enviados pelo Master (desenho 08: .pem, .key, .crt, .p12, .pfx) e
 * confere que formam um par. Devolve os dois em PEM, que é o que o mTLS usa.
 */
final class CertificadoDoBanco
{
    /**
     * @param array<string, string> $arquivos nome do arquivo => conteúdo
     *
     * @return array{certificado: string, chave: string}
     */
    public function ler(array $arquivos): array
    {
        $certificado = null;
        $chave = null;
        foreach ($arquivos as $nome => $conteudo) {
            if (preg_match('/\.(p12|pfx)$/i', $nome)) {
                [$certificado, $chave] = $this->doPkcs12($conteudo);
                continue;
            }
            if (str_contains($conteudo, 'PRIVATE KEY')) {
                $chave = $conteudo;
            } elseif (str_contains($conteudo, 'BEGIN CERTIFICATE')) {
                $certificado = $conteudo;
            }
        }
        if (null === $certificado || null === $chave) {
            throw new \DomainException('Envie o certificado e a chave privada (.pem e .key, ou um .p12 sem senha).');
        }
        $this->exigirPar($certificado, $chave);

        return ['certificado' => $certificado, 'chave' => $chave];
    }

    /** @return array{string, string} */
    private function doPkcs12(string $conteudo): array
    {
        $partes = [];
        if (!openssl_pkcs12_read($conteudo, $partes, '')) {
            throw new \DomainException('Não foi possível abrir o .p12. Envie sem senha, ou o .pem e o .key.');
        }

        return [(string) $partes['cert'], (string) $partes['pkey']];
    }

    private function exigirPar(string $certificado, string $chave): void
    {
        $x509 = @openssl_x509_read($certificado);
        $privada = @openssl_pkey_get_private($chave);
        if (false === $x509 || false === $privada) {
            throw new \DomainException('O certificado ou a chave privada não pôde ser lido.');
        }
        if (!openssl_x509_check_private_key($x509, $privada)) {
            throw new \DomainException('A chave privada não é a deste certificado.');
        }
    }
}
