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

    public function enviar(ChaveDeArquivo $chave, mixed $conteudo, int $tamanhoBytes, string $sha256, string $mimeType): void
    {
        if (!\is_resource($conteudo)) {
            throw new \InvalidArgumentException('O conteúdo tem de ser um stream aberto.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new \InvalidArgumentException('O SHA-256 tem de vir em hex minúsculo de 64 caracteres.');
        }

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

    public function conteudoDe(ChaveDeArquivo $chave): ?string
    {
        return $this->objetos[LayoutDeChaveRemota::chaveDe($chave)]['corpo'] ?? null;
    }
}
