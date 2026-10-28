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
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as ExcecaoDoHttpClient;

/**
 * O espelho no Cloudflare R2, pela API S3 do `async-aws/s3` (spec P2 §4, DP-2).
 *
 * **Só `PutObject`, `HeadObject` e `GetObject`.** Não existe chamada de exclusão nesta classe nem em
 * nenhum outro lugar de `src/` (regra 0 da P2) — `EspelhoRemotoArquiteturaTest` trava. O `GetObject`
 * é só da leitura de recuperação ({@see baixar()}), que confere tamanho e SHA-256 do que chegou.
 *
 * ## Integridade, em três camadas — e o que cada uma prova
 *
 *  1. **antes do PUT, o próprio adaptador relê o stream** e calcula o SHA-256 e o tamanho; se não
 *     baterem com o declarado, nada é enviado. É ESTA camada que garante o contrato "conteúdo que
 *     não confere não é gravado", independentemente do destino (o stream precisa ser rebobinável);
 *  2. o PUT leva `x-amz-checksum-sha256`: o destino recalcula o SHA do que RECEBEU e recusa o objeto
 *     se não bater — protege o trajeto. Que o R2 respeita esse cabeçalho só se prova na P2.4;
 *  3. depois do PUT, um HEAD confere tamanho e o metadado `sha256`. Isso prova que o objeto existe
 *     com o tamanho certo e com o metadado que a idempotência usa — **não** prova os bytes (o
 *     metadado é o que este mesmo PUT gravou); quem prova os bytes são as camadas 1 e 2.
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
        self::exigirStreamLegivelERebobinavel($conteudo);
        self::exigirParametrosDoEnvio($tamanhoBytes, $sha256, $mimeType);

        $chaveRemota = LayoutDeChaveRemota::chaveDe($chave);
        self::exigirConteudoQueConfere($conteudo, $tamanhoBytes, $sha256, $chaveRemota);

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

        $this->exigirQueOGravadoConfere($chave, $chaveRemota, $tamanhoBytes, $sha256);
    }

    private static function exigirParametrosDoEnvio(int $tamanhoBytes, string $sha256, string $mimeType): void
    {
        if ($tamanhoBytes < 0) {
            throw new \InvalidArgumentException('Tamanho negativo.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new \InvalidArgumentException('O SHA-256 tem de vir em hex minúsculo de 64 caracteres.');
        }
        if (preg_match('/\A[!-~][ -~]{0,254}\z/', $mimeType) !== 1) {
            throw new \InvalidArgumentException('MIME inválido para cabeçalho HTTP.');
        }
    }

    /** Lê de volta o objeto enviado e exige tamanho e SHA iguais aos do disco. */
    private function exigirQueOGravadoConfere(ChaveDeArquivo $chave, string $chaveRemota, int $tamanhoBytes, string $sha256): void
    {
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

        // Sem Content-Length o tamanho é desconhecido, não 0: o (int) de null fazia o objeto íntegro
        // parecer vazio (ver ClienteR2::CABECALHOS).
        $tamanho = $resultado->getContentLength();
        if ($tamanho === null) {
            throw new FalhaNoEspelhoRemoto(sprintf('O destino não informou o tamanho de %s (HEAD sem Content-Length).', $chaveRemota));
        }

        $sha256 = $resultado->getMetadata()['sha256'] ?? null;

        return new MetadadosRemotos(
            $chaveRemota,
            $tamanho,
            \is_string($sha256) && preg_match('/\A[0-9a-f]{64}\z/', $sha256) === 1 ? $sha256 : null,
            $resultado->getContentType(),
            $resultado->getLastModified(),
        );
    }

    public function existe(ChaveDeArquivo $chave): bool
    {
        return $this->consultar($chave) !== null;
    }

    public function baixar(ChaveDeArquivo $chave, mixed $destino, int $tamanhoEsperado, string $sha256Esperado): void
    {
        self::exigirStreamGravavel($destino);
        if ($tamanhoEsperado < 0) {
            throw new \InvalidArgumentException('Tamanho negativo.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha256Esperado) !== 1) {
            throw new \InvalidArgumentException('O SHA-256 tem de vir em hex minúsculo de 64 caracteres.');
        }

        $chaveRemota = LayoutDeChaveRemota::chaveDe($chave);
        [$recebidos, $medido] = $this->baixarEmBlocos($chaveRemota, $destino, $tamanhoEsperado);

        if ($recebidos !== $tamanhoEsperado || !hash_equals($sha256Esperado, $medido)) {
            throw new FalhaNoEspelhoRemoto(sprintf(
                'O objeto remoto %s não confere com o esperado (esperado %d bytes / %s; recebido %d bytes / %s).',
                $chaveRemota,
                $tamanhoEsperado,
                $sha256Esperado,
                $recebidos,
                $medido,
            ));
        }
    }

    /**
     * O `GetObject` lido bloco a bloco: o conteúdo nunca fica inteiro numa string. Passou do tamanho
     * esperado, o download para ali (não adianta baixar o resto de um objeto que já não confere).
     *
     * @param resource $destino
     *
     * @return array{0: int, 1: string} bytes recebidos e SHA-256 do que foi recebido
     */
    private function baixarEmBlocos(string $chaveRemota, mixed $destino, int $tamanhoEsperado): array
    {
        $contexto  = hash_init('sha256');
        $recebidos = 0;

        try {
            $corpo = $this->cliente->getObject(['Bucket' => $this->bucket, 'Key' => $chaveRemota])->getBody();
            foreach ($corpo->getChunks() as $bloco) {
                $recebidos += \strlen($bloco);
                if ($recebidos > $tamanhoEsperado) {
                    throw new FalhaNoEspelhoRemoto(sprintf('O objeto remoto %s é maior que o esperado (%d bytes); o download foi interrompido.', $chaveRemota, $tamanhoEsperado));
                }
                hash_update($contexto, $bloco);
                self::escreverTudo($destino, $bloco, $chaveRemota);
            }
        } catch (NoSuchKeyException) {
            throw new FalhaNoEspelhoRemoto(sprintf('O objeto remoto %s não existe no espelho.', $chaveRemota));
        } catch (ExcecaoDoAsyncAws $e) {
            throw self::falha('baixar', $chaveRemota, $e);
        } catch (ExcecaoDoHttpClient $e) {
            throw new FalhaNoEspelhoRemoto(
                sprintf('Não foi possível baixar %s do espelho remoto: falha de rede ou de transporte (%s).', $chaveRemota, (new \ReflectionClass($e))->getShortName()),
                0,
                $e,
            );
        }

        return [$recebidos, hash_final($contexto)];
    }

    /** @param resource $destino */
    private static function escreverTudo(mixed $destino, string $bloco, string $chaveRemota): void
    {
        $restante = $bloco;
        while ($restante !== '') {
            $escritos = @fwrite($destino, $restante);
            if ($escritos === false || $escritos === 0) {
                throw new FalhaNoEspelhoRemoto(sprintf('Não foi possível gravar localmente o que veio de %s (destino recusou a escrita).', $chaveRemota));
            }
            $restante = substr($restante, $escritos);
        }
    }

    private static function exigirStreamGravavel(mixed $destino): void
    {
        if (!\is_resource($destino) || get_resource_type($destino) !== 'stream') {
            throw new \InvalidArgumentException('O destino tem de ser um stream aberto.');
        }

        $modo = stream_get_meta_data($destino)['mode'];
        if (strpbrk($modo, 'waxc+') === false) {
            throw new \InvalidArgumentException('O stream de destino não é gravável.');
        }
    }

    /**
     * Rebobinável é exigência do contrato: o adaptador lê o stream duas vezes (o SHA e o envio), e o
     * cliente S3 recusaria um stream sem `seek` com um erro que pareceria de rede. Erro de quem chama
     * não pode virar falha "transitória" que a réplica repete com backoff.
     */
    private static function exigirStreamLegivelERebobinavel(mixed $conteudo): void
    {
        if (!\is_resource($conteudo) || get_resource_type($conteudo) !== 'stream') {
            throw new \InvalidArgumentException('O conteúdo tem de ser um stream aberto.');
        }

        $meta = stream_get_meta_data($conteudo);
        $modo = $meta['mode'];
        if (!str_contains($modo, 'r') && !str_contains($modo, '+')) {
            throw new \InvalidArgumentException('O stream do conteúdo não é legível.');
        }
        if ($meta['seekable'] !== true) {
            throw new \InvalidArgumentException('O stream do conteúdo tem de ser rebobinável.');
        }
    }

    /** Camada 1: o que vai ser enviado é o que o chamador disse que é. Lê em blocos, sem carregar tudo. */
    private static function exigirConteudoQueConfere(mixed $conteudo, int $tamanhoBytes, string $sha256, string $chaveRemota): void
    {
        if (!rewind($conteudo)) {
            throw new \InvalidArgumentException('Não foi possível rebobinar o stream do conteúdo.');
        }

        $contexto = hash_init('sha256');
        $lidos    = hash_update_stream($contexto, $conteudo);
        $medido   = hash_final($contexto);

        if (!rewind($conteudo)) {
            throw new \InvalidArgumentException('Não foi possível rebobinar o stream do conteúdo.');
        }

        if ($lidos !== $tamanhoBytes || !hash_equals($medido, $sha256)) {
            throw new FalhaNoEspelhoRemoto(sprintf(
                'O conteúdo de %s não confere com o declarado (declarado %d bytes / %s; lido %d bytes / %s); nada foi enviado.',
                $chaveRemota,
                $tamanhoBytes,
                $sha256,
                $lidos,
                $medido,
            ));
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
