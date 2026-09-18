<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\Entity\ClienteDocumento;
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
