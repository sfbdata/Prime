<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\R2;

use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\MetadadosRemotos;
use AsyncAws\Core\Exception\Exception as ExcecaoDoAsyncAws;
use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\S3Client;

/**
 * O espelho no Cloudflare R2, pela API S3 do `async-aws/s3` (spec P2 §4, DP-2).
 *
 * **Só `PutObject` e `HeadObject`.** Não existe chamada de exclusão nesta classe nem em nenhum outro
 * lugar de `src/` (regra 0 da P2) — `EspelhoRemotoArquiteturaTest` trava.
 *
 * ## Integridade, em duas camadas
 *
 *  1. o PUT leva `x-amz-checksum-sha256`: o destino recalcula o SHA-256 do que recebeu e recusa
 *     o objeto se não bater — conteúdo corrompido no caminho não chega a ser gravado;
 *  2. depois do PUT, um HEAD confere tamanho e o metadado `sha256` do que ficou lá. Só então a
 *     gravação conta como feita.
 *
 * O SHA vem do chamador, medido no disco local, e vai também como metadado (`x-amz-meta-sha256`):
 * é com ele que a réplica da P2.3 sabe, com um HEAD e sem baixar nada, se o objeto já está certo
 * (idempotência) e que a reconciliação compara banco × R2.
 *
 * ## Erros
 *
 * Toda falha do destino vira {@see FalhaNoEspelhoRemoto}, com status HTTP, código S3 e chave
 * remota — nunca credencial, cabeçalho assinado nem corpo de resposta. "Não existe" (404 no HEAD)
 * é `null`, não falha (D10).
 *
 * ⚠️ Validado contra um S3 SIMULADO (sem rede). O comportamento do R2 de verdade — aceitar o
 * `x-amz-checksum-sha256`, o 404 do HEAD, a assinatura com chave Unicode — só se prova contra um
 * bucket real, na P2.4.
 */
final class EspelhoRemotoR2 implements EspelhoRemoto
{
    public function __construct(
        private readonly S3Client $cliente,
        private readonly string $bucket,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{1,61}[a-z0-9]\z/', $bucket) !== 1) {
            throw new \InvalidArgumentException('Nome de bucket inválido para o R2.');
        }
    }

    public function enviar(ChaveDeArquivo $chave, mixed $conteudo, int $tamanhoBytes, string $sha256, string $mimeType): void
    {
        self::exigirStreamLegivel($conteudo);
        if ($tamanhoBytes < 0) {
            throw new \InvalidArgumentException('Tamanho negativo.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new \InvalidArgumentException('O SHA-256 tem de vir em hex minúsculo de 64 caracteres.');
        }
        if (preg_match('/\A[!-~][ -~]{0,254}\z/', $mimeType) !== 1) {
            throw new \InvalidArgumentException('MIME inválido para cabeçalho HTTP.');
        }

        $chaveRemota = LayoutDeChaveRemota::chaveDe($chave);

        try {
            $this->cliente->putObject([
                'Bucket'         => $this->bucket,
                'Key'            => $chaveRemota,
                'Body'           => $conteudo,
                'ContentLength'  => $tamanhoBytes,
                'ContentType'    => $mimeType,
                'ChecksumSHA256' => base64_encode((string) hex2bin($sha256)),
                'Metadata'       => ['sha256' => $sha256],
            ])->resolve();
        } catch (ExcecaoDoAsyncAws $e) {
            throw self::falha('enviar', $chaveRemota, $e);
        }

        $gravado = $this->consultar($chave);
        if ($gravado === null || !$gravado->confereCom($tamanhoBytes, $sha256)) {
            throw new FalhaNoEspelhoRemoto(sprintf(
                'O objeto remoto %s não confere depois do envio (esperado %d bytes e sha256 %s; destino diz %s).',
                $chaveRemota,
                $tamanhoBytes,
                $sha256,
                $gravado === null ? 'ausente' : sprintf('%d bytes, sha256 %s', $gravado->tamanhoBytes, $gravado->sha256 ?? 'sem metadado'),
            ));
        }
    }

    public function consultar(ChaveDeArquivo $chave): ?MetadadosRemotos
    {
        $chaveRemota = LayoutDeChaveRemota::chaveDe($chave);

        try {
            $resultado = $this->cliente->headObject(['Bucket' => $this->bucket, 'Key' => $chaveRemota]);
            $resultado->resolve();
        } catch (NoSuchKeyException) {
            return null;
        } catch (ExcecaoDoAsyncAws $e) {
            throw self::falha('consultar', $chaveRemota, $e);
        }

        $sha256 = $resultado->getMetadata()['sha256'] ?? null;

        return new MetadadosRemotos(
            $chaveRemota,
            (int) $resultado->getContentLength(),
            \is_string($sha256) && preg_match('/\A[0-9a-f]{64}\z/', $sha256) === 1 ? $sha256 : null,
            $resultado->getContentType(),
            $resultado->getLastModified(),
        );
    }

    public function existe(ChaveDeArquivo $chave): bool
    {
        return $this->consultar($chave) !== null;
    }

    private static function exigirStreamLegivel(mixed $conteudo): void
    {
        if (!\is_resource($conteudo) || get_resource_type($conteudo) !== 'stream') {
            throw new \InvalidArgumentException('O conteúdo tem de ser um stream aberto.');
        }

        $modo = (string) (stream_get_meta_data($conteudo)['mode'] ?? '');
        if (!str_contains($modo, 'r') && !str_contains($modo, '+')) {
            throw new \InvalidArgumentException('O stream do conteúdo não é legível.');
        }
    }

    /** Status HTTP + código S3 + chave. Nada de mensagem crua: ela pode trazer URL assinada ou corpo. */
    private static function falha(string $operacao, string $chaveRemota, ExcecaoDoAsyncAws $e): FalhaNoEspelhoRemoto
    {
        $detalhe = $e instanceof HttpException
            ? sprintf('HTTP %d, código %s', $e->getResponse()->getStatusCode(), $e->getAwsCode() ?? 'desconhecido')
            : 'falha de rede ou de transporte (' . (new \ReflectionClass($e))->getShortName() . ')';

        return new FalhaNoEspelhoRemoto(
            sprintf('Não foi possível %s %s no espelho remoto: %s.', $operacao, $chaveRemota, $detalhe),
            0,
            $e,
        );
    }
}
