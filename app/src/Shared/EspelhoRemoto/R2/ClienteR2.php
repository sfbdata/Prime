<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\R2;

use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\S3\S3Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Monta o `S3Client` do R2 com tudo explícito.
 *
 * **A credencial vai como provedor FIXO** (`Credentials`), e não como opção de configuração. Com
 * provedor nulo, o `async-aws` monta a cadeia padrão — configuração, WebIdentity, `~/.aws`, contêiner
 * e IMDS — e lê do ambiente, em grupos separados, `AWS_ROLE_ARN`/`AWS_WEB_IDENTITY_TOKEN_FILE`: com
 * eles presentes, faria `AssumeRole` no STS da AWS usando a chave do R2, e depois cairia para os
 * outros provedores. Com o provedor fixo, nenhuma dessas buscas acontece. Endpoint e região também
 * vêm sempre, então `AWS_REGION`/`AWS_PROFILE` sobrando não mudam destino nem assinatura.
 *
 *  - `region = auto`: o R2 assina com essa região;
 *  - `pathStyleEndpoint`: `https://<conta>.r2.cloudflarestorage.com/<bucket>/<chave>` — sem
 *    subdomínio por bucket;
 *  - `sendChunkedBody = false`: corpo inteiro com `x-amz-content-sha256` calculado, sem
 *    `aws-chunked` (não depender de suporte do destino a esse modo);
 *  - `Accept-Encoding: identity` em toda requisição: sem ele, o curl anuncia gzip, a borda da
 *    Cloudflare comprime a resposta do HEAD de texto (`text/plain` a partir de ~50 B) e tira o
 *    `Content-Length`, e o tamanho remoto chegava como 0 (rodada 4 da P2.5, 26/10/2026).
 *
 * Nada aqui é ligado no container ainda (P2.2): quem montar o espelho é a P2.3, com as credenciais
 * vindas de variável de ambiente de produção — nunca de arquivo do repositório.
 */
final class ClienteR2
{
    /**
     * Teto de duração de UMA requisição ao R2, em segundos (inclui o upload do corpo). Menor que a
     * posse do worker (`ReplicadorDeArquivos::POSSE_EM_SEGUNDOS`): um PUT nunca termina depois de a
     * posse vencer. 600 s cobrem um vídeo de 100 MB a ~170 kB/s.
     */
    public const DURACAO_MAXIMA_DA_REQUISICAO = 600;

    /** Sem bytes por este tempo, a requisição é abandonada. */
    public const OCIOSIDADE_MAXIMA = 60;

    /** Resposta sem compressão: o HEAD precisa trazer o `Content-Length` do objeto. */
    public const CABECALHOS = ['Accept-Encoding' => 'identity'];

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
            'pathStyleEndpoint' => 'true',
            'sendChunkedBody'   => 'false',
        ], new Credentials($chaveDeAcesso, $segredo), $http?->withOptions(['headers' => self::CABECALHOS]) ?? HttpClient::create([
            'timeout'      => self::OCIOSIDADE_MAXIMA,
            'max_duration' => self::DURACAO_MAXIMA_DA_REQUISICAO,
            'headers'      => self::CABECALHOS,
        ]), $logger);
    }
}
