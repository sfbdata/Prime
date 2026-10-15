<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O contrato do {@see EspelhoRemoto} — o que a réplica da P2.3 pode supor de QUALQUER destino.
 *
 * Roda contra o adaptador R2 (sobre o S3 simulado, sem rede) e contra o dublê em memória: se os dois
 * passam aqui, o dublê é um substituto honesto do real nos testes da P2.3.
 */
abstract class EspelhoRemotoContratoTestCase extends TestCase
{
    abstract protected function espelho(): EspelhoRemoto;

    #[TestDox('enviar e consultar: tamanho, SHA e MIME voltam como foram enviados')]
    public function testEnviarEConsultar(): void
    {
        $chave = $this->chave('a.pdf');
        $this->enviar($chave, "%PDF-1.4\nconteúdo\n", 'application/pdf');

        $meta = $this->espelho()->consultar($chave);

        self::assertNotNull($meta);
        self::assertSame(\strlen("%PDF-1.4\nconteúdo\n"), $meta->tamanhoBytes);
        self::assertSame(hash('sha256', "%PDF-1.4\nconteúdo\n"), $meta->sha256);
        self::assertSame('application/pdf', $meta->mimeType);
        self::assertSame('core/t/1/uploads/pastas/a.pdf', $meta->chaveRemota);
        self::assertTrue($meta->confereCom(\strlen("%PDF-1.4\nconteúdo\n"), hash('sha256', "%PDF-1.4\nconteúdo\n")));
    }

    #[TestDox('ausente: consultar devolve null e existe devolve false — ausência não é falha')]
    public function testAusente(): void
    {
        self::assertNull($this->espelho()->consultar($this->chave('nunca-enviado.pdf')));
        self::assertFalse($this->espelho()->existe($this->chave('nunca-enviado.pdf')));
    }

    #[TestDox('existe depois de enviar')]
    public function testExisteDepoisDeEnviar(): void
    {
        $chave = $this->chave('b.pdf');
        $this->enviar($chave, 'b', 'application/pdf');

        self::assertTrue($this->espelho()->existe($chave));
    }

    #[TestDox('reenviar a mesma chave é idempotente com o mesmo conteúdo e substitui com conteúdo novo')]
    public function testReenvio(): void
    {
        $chave = $this->chave('c.pdf');
        $this->enviar($chave, 'versão 1', 'application/pdf');
        $this->enviar($chave, 'versão 1', 'application/pdf');
        self::assertSame(hash('sha256', 'versão 1'), $this->espelho()->consultar($chave)?->sha256);

        $this->enviar($chave, 'versão 2', 'application/pdf');
        self::assertSame(hash('sha256', 'versão 2'), $this->espelho()->consultar($chave)?->sha256);
    }

    #[TestDox('arquivo de 0 byte é espelhado como está (os 114 do acervo)')]
    public function testArquivoVazio(): void
    {
        $chave = $this->chave('vazio.bin');
        $this->enviar($chave, '', 'application/octet-stream');

        self::assertSame(0, $this->espelho()->consultar($chave)?->tamanhoBytes);
    }

    #[TestDox('conteúdo binário de 3 MB atravessa intacto')]
    public function testConteudoGrandeEBinario(): void
    {
        $chave    = $this->chave('grande.bin');
        $conteudo = random_bytes(3 * 1024 * 1024);
        $this->enviar($chave, $conteudo, 'application/octet-stream');

        self::assertTrue($this->espelho()->consultar($chave)?->confereCom(\strlen($conteudo), hash('sha256', $conteudo)));
    }

    #[TestDox('SHA declarado que não bate com o conteúdo: falha e NADA fica gravado')]
    public function testShaErradoNaoGrava(): void
    {
        $chave = $this->chave('corrompido.pdf');

        try {
            $this->espelho()->enviar($chave, $this->stream('conteúdo real'), \strlen('conteúdo real'), hash('sha256', 'outro conteúdo'), 'application/pdf');
            self::fail('SHA errado tinha de falhar');
        } catch (FalhaNoEspelhoRemoto) {
        }

        self::assertFalse($this->espelho()->existe($chave));
    }

    #[TestDox('chaves de escritórios diferentes não colidem, mesmo com o mesmo nome (R1)')]
    public function testEscritoriosNaoColidem(): void
    {
        $de1 = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'mesmo.pdf');
        $de2 = new ChaveDeArquivo(EscopoDeArquivo::deTenant(2), CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'mesmo.pdf');
        $this->enviar($de1, 'do escritório 1', 'application/pdf');

        self::assertFalse($this->espelho()->existe($de2), 'o objeto do escritório 1 não pode aparecer para o 2');
    }

    #[TestDox('parâmetros inválidos são recusados antes de qualquer envio')]
    public function testParametrosInvalidos(): void
    {
        $chave = $this->chave('x.pdf');

        foreach ([
            'sha curto'      => fn () => $this->espelho()->enviar($chave, $this->stream('x'), 1, 'abc', 'application/pdf'),
            'sha maiúsculo'  => fn () => $this->espelho()->enviar($chave, $this->stream('x'), 1, strtoupper(hash('sha256', 'x')), 'application/pdf'),
            'não é stream'   => fn () => $this->espelho()->enviar($chave, 'x', 1, hash('sha256', 'x'), 'application/pdf'),
        ] as $caso => $chamada) {
            try {
                $chamada();
                self::fail($caso . ': devia recusar');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertFalse($this->espelho()->existe($chave));
    }

    protected function chave(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
    }

    protected function enviar(ChaveDeArquivo $chave, string $conteudo, string $mime): void
    {
        $this->espelho()->enviar($chave, $this->stream($conteudo), \strlen($conteudo), hash('sha256', $conteudo), $mime);
    }

    /** @return resource */
    protected function stream(string $conteudo): mixed
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $conteudo);
        rewind($stream);

        return $stream;
    }
}
