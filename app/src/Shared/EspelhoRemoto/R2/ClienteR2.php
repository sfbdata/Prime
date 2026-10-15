<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\R2;

use AsyncAws\S3\S3Client;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Monta o `S3Client` do R2 com tudo explícito — nada lido de `AWS_*` do ambiente nem de `~/.aws`.
 *
 * O `async-aws` cai para `AWS_ACCESS_KEY_ID`/`AWS_REGION`/perfis quando a opção não vem. Passando
 * credencial, endpoint e região sempre, um ambiente com variáveis da AWS sobrando não muda o
 * destino nem a identidade sem ninguém perceber.
 *
 *  - `region = auto`: o R2 assina com essa região;
 *  - `pathStyleEndpoint`: `https://<conta>.r2.cloudflarestorage.com/<bucket>/<chave>` — sem
 *    subdomínio por bucket;
 *  - `sendChunkedBody = false`: corpo inteiro com `x-amz-content-sha256` calculado, sem
 *    `aws-chunked` (não depender de suporte do destino a esse modo).
 *
 * Nada aqui é ligado no container ainda (P2.2): quem montar o espelho é a P2.3, com as credenciais
 * vindas de variável de ambiente de produção — nunca de arquivo do repositório.
 */
final class ClienteR2
{
    private function __construct()
    {
    }

    public static function criar(
        string $endpoint,
        string $chaveDeAcesso,
        string $segredo,
        ?HttpClientInterface $http = null,
        ?LoggerInterface $logger = null,
    ): S3Client {
        if (preg_match('#\Ahttps://[a-z0-9.-]+(?::\d+)?\z#', $endpoint) !== 1) {
            throw new \InvalidArgumentException('O endpoint do R2 tem de ser https://<host>, sem caminho.');
        }
        if (trim($chaveDeAcesso) === '' || trim($segredo) === '') {
            throw new \InvalidArgumentException('Credencial do R2 ausente.');
        }

        return new S3Client([
            'endpoint'          => $endpoint,
            'region'            => 'auto',
            'accessKeyId'       => $chaveDeAcesso,
            'accessKeySecret'   => $segredo,
            'pathStyleEndpoint' => 'true',
            'sendChunkedBody'   => 'false',
        ], null, $http, $logger);
    }
}
