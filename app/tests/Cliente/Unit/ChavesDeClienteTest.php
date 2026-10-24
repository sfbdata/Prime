<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChavesDeCliente::class)]
final class ChavesDeClienteTest extends TestCase
{
    #[TestDox('documento(): categoria CLIENTE_DOCUMENTO e escopo do tenant da entidade')]
    public function testDocumentoUsaCategoriaEEscopoDaEntidade(): void
    {
        $chave = ChavesDeCliente::documento($this->documento($this->tenant(7), 'doc.pdf'));

        self::assertSame(CategoriaDeArquivo::CLIENTE_DOCUMENTO, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
    }

    #[TestDox('documento(): sem tenant na entidade, recusa')]
    public function testDocumentoSemTenantLanca(): void
    {
        $documento = (new ClienteDocumento())->setCaminhoArquivo('doc.pdf');

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeCliente::documento($documento);
    }

    #[TestDox('documento(): tenant sem id é recusado')]
    public function testDocumentoComTenantSemIdLanca(): void
    {
        $documento = $this->documento(new Tenant(), 'doc.pdf');

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeCliente::documento($documento);
    }

    /** @return iterable<string, array{string}> */
    public static function nomesLegados(): iterable
    {
        yield 'espaço nas bordas' => [' doc.pdf '];
        yield 'termina em ponto'  => ['hash.'];
    }

    #[DataProvider('nomesLegados')]
    #[TestDox('documento(): nome preservado byte a byte')]
    public function testNomeEhPreservadoByteAByte(string $nome): void
    {
        $chave = ChavesDeCliente::documento($this->documento($this->tenant(7), $nome));

        self::assertSame($nome, $chave->nome);
    }

    #[TestDox('documento(): recebe só a entidade')]
    public function testFabricaNaoAceitaTenantExterno(): void
    {
        $parametros = (new \ReflectionMethod(ChavesDeCliente::class, 'documento'))->getParameters();

        self::assertCount(1, $parametros);
        self::assertSame(ClienteDocumento::class, (string) $parametros[0]->getType());
    }

    #[TestDox('foto(): categoria CLIENTE_FOTO, escopo do escritório do cliente e o nome guardado')]
    public function testFotoUsaCategoriaEEscopoDoCliente(): void
    {
        $cliente = $this->cliente($this->tenant(7));
        $cliente->trocarFoto('a1b2.jpg', new \DateTimeImmutable());

        $chave = ChavesDeCliente::foto($cliente);

        self::assertSame(CategoriaDeArquivo::CLIENTE_FOTO, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
        self::assertSame('a1b2.jpg', $chave->nome);
    }

    #[TestDox('foto(): cliente sem foto não tem chave')]
    public function testFotoSemFotoLanca(): void
    {
        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeCliente::foto($this->cliente($this->tenant(7)));
    }

    #[TestDox('fotoPorNome() e novaFoto(): escopo do cliente; cliente sem escritório é recusado')]
    public function testFotoPorNomeENovaFotoUsamOEscopoDoCliente(): void
    {
        $cliente = $this->cliente($this->tenant(9));

        $anterior = ChavesDeCliente::fotoPorNome($cliente, 'velha.png');
        $nova     = ChavesDeCliente::novaFoto($cliente, 'jpg');

        self::assertSame([9, CategoriaDeArquivo::CLIENTE_FOTO, 'velha.png'], [$anterior->escopo->tenantIdOuNull(), $anterior->categoria, $anterior->nome]);
        self::assertSame([9, CategoriaDeArquivo::CLIENTE_FOTO, 'jpg'], [$nova->escopo->tenantIdOuNull(), $nova->categoria, $nova->extensao]);

        $this->expectException(ChaveDeArquivoInvalida::class);
        ChavesDeCliente::novaFoto(new ClientePF(), 'jpg');
    }

    private function cliente(Tenant $tenant): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setTenant($tenant);

        return $cliente;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function documento(Tenant $tenant, string $caminhoArquivo): ClienteDocumento
    {
        return (new ClienteDocumento())->setTenant($tenant)->setCaminhoArquivo($caminhoArquivo);
    }
}
