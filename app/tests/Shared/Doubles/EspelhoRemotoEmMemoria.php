<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\MetadadosRemotos;

/**
 * Dublê do {@see EspelhoRemoto}: guarda em memória, pela MESMA chave do layout, e cumpre o contrato
 * que `EspelhoRemotoContratoTestCase` exige do adaptador real — inclusive recusar conteúdo cujo SHA ou
 * tamanho não bate com o declarado. É o destino que a réplica da P2.3 vai usar nos testes.
 */
final class EspelhoRemotoEmMemoria implements EspelhoRemoto
{
    /** @var array<string, array{corpo: string, sha256: string, mime: string}> */
    private array $objetos = [];

    /** Quantas vezes {@see baixar()} foi chamado: a prova de que a leitura saiu do cache. */
    public int $downloads = 0;

    /** Ligado, todo download falha como o R2 fora do ar. */
    public bool $foraDoAr = false;

    public function enviar(ChaveDeArquivo $chave, mixed $conteudo, int $tamanhoBytes, string $sha256, string $mimeType): void
    {
        // As mesmas recusas do adaptador real — o dublê não pode aceitar o que o real recusa.
        if (!\is_resource($conteudo) || (stream_get_meta_data($conteudo)['seekable'] ?? false) !== true) {
            throw new \InvalidArgumentException('O conteúdo tem de ser um stream aberto e rebobinável.');
        }
        if ($tamanhoBytes < 0) {
            throw new \InvalidArgumentException('Tamanho negativo.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new \InvalidArgumentException('O SHA-256 tem de vir em hex minúsculo de 64 caracteres.');
        }
        if (preg_match('/\A[!-~][ -~]{0,254}\z/', $mimeType) !== 1) {
            throw new \InvalidArgumentException('MIME inválido para cabeçalho HTTP.');
        }

        rewind($conteudo);
        $corpo = (string) stream_get_contents($conteudo);
        if (\strlen($corpo) !== $tamanhoBytes || !hash_equals(hash('sha256', $corpo), $sha256)) {
            throw new FalhaNoEspelhoRemoto('O conteúdo não confere com o tamanho/SHA declarados; nada foi gravado.');
        }

        $this->objetos[LayoutDeChaveRemota::chaveDe($chave)] = ['corpo' => $corpo, 'sha256' => $sha256, 'mime' => $mimeType];
    }

    public function consultar(ChaveDeArquivo $chave): ?MetadadosRemotos
    {
        $chaveRemota = LayoutDeChaveRemota::chaveDe($chave);
        $objeto      = $this->objetos[$chaveRemota] ?? null;

        return $objeto === null
            ? null
            : new MetadadosRemotos($chaveRemota, \strlen($objeto['corpo']), $objeto['sha256'], $objeto['mime'], null);
    }

    public function existe(ChaveDeArquivo $chave): bool
    {
        return $this->consultar($chave) !== null;
    }

    public function baixar(ChaveDeArquivo $chave, mixed $destino, int $tamanhoEsperado, string $sha256Esperado): void
    {
        if (!\is_resource($destino) || strpbrk(stream_get_meta_data($destino)['mode'], 'waxc+') === false) {
            throw new \InvalidArgumentException('O destino tem de ser um stream gravável.');
        }
        if ($tamanhoEsperado < 0) {
            throw new \InvalidArgumentException('Tamanho negativo.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha256Esperado) !== 1) {
            throw new \InvalidArgumentException('O SHA-256 tem de vir em hex minúsculo de 64 caracteres.');
        }

        ++$this->downloads;
        if ($this->foraDoAr) {
            // Como o real: a mensagem leva a chave remota (com o nome do arquivo).
            throw new FalhaNoEspelhoRemoto(sprintf('Não foi possível baixar %s do espelho remoto: falha de rede ou de transporte (simulada).', LayoutDeChaveRemota::chaveDe($chave)));
        }

        $objeto = $this->objetos[LayoutDeChaveRemota::chaveDe($chave)] ?? null;
        if ($objeto === null) {
            throw new FalhaNoEspelhoRemoto('O objeto remoto não existe no espelho.');
        }

        // Como o real: escreve o que chegou antes de conferir. Quem chama descarta o destino na falha.
        fwrite($destino, $objeto['corpo']);
        if (\strlen($objeto['corpo']) !== $tamanhoEsperado || !hash_equals($sha256Esperado, hash('sha256', $objeto['corpo']))) {
            throw new FalhaNoEspelhoRemoto('O objeto remoto não confere com o esperado.');
        }
    }

    /**
     * Grava direto no "bucket", sem as conferências do envio: é como o teste simula um objeto remoto
     * corrompido (o metadado diz um SHA, os bytes são outros).
     */
    public function semear(ChaveDeArquivo $chave, string $corpo, ?string $sha256 = null, string $mime = 'application/octet-stream'): void
    {
        $this->objetos[LayoutDeChaveRemota::chaveDe($chave)] = ['corpo' => $corpo, 'sha256' => $sha256 ?? hash('sha256', $corpo), 'mime' => $mime];
    }

    public function conteudoDe(ChaveDeArquivo $chave): ?string
    {
        return $this->objetos[LayoutDeChaveRemota::chaveDe($chave)]['corpo'] ?? null;
    }
}
