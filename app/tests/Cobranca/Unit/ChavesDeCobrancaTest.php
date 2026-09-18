<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\AcordoDocumento;
use App\Cobranca\Entity\CarteiraDocumento;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os três documentos de Cobrança (caso, acordo, carteira) moram no MESMO diretório isolado por
 * tenant — a categoria é uma só. O que muda é a entidade, e é dela que o escopo sai: os UseCases
 * recebem um `$tenant` por parâmetro, mas a chave nunca é montada a partir dele.
 */
#[CoversClass(ChavesDeCobranca::class)]
final class ChavesDeCobrancaTest extends TestCase
{
    #[TestDox('documentoDeCaso(): categoria COBRANCA_DOCUMENTO e escopo da entidade')]
    public function testDocumentoDeCaso(): void
    {
        $doc   = (new CobrancaDocumento())->setTenant($this->tenant(7))->setCaminhoArquivo('h1');
        $chave = ChavesDeCobranca::documentoDeCaso($doc);

        self::assertSame(CategoriaDeArquivo::COBRANCA_DOCUMENTO, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
        self::assertSame('h1', $chave->nome);
    }

    #[TestDox('documentoDeAcordo(): categoria COBRANCA_DOCUMENTO e escopo da entidade')]
    public function testDocumentoDeAcordo(): void
    {
        $doc   = (new AcordoDocumento())->setTenant($this->tenant(8))->setCaminhoArquivo('h2');
        $chave = ChavesDeCobranca::documentoDeAcordo($doc);

        self::assertSame(CategoriaDeArquivo::COBRANCA_DOCUMENTO, $chave->categoria);
        self::assertSame(8, $chave->escopo->tenantIdOuNull());
        self::assertSame('h2', $chave->nome);
    }

    #[TestDox('documentoDeCarteira(): categoria COBRANCA_DOCUMENTO e escopo da entidade')]
    public function testDocumentoDeCarteira(): void
    {
        $doc   = (new CarteiraDocumento())->setTenant($this->tenant(9))->setCaminhoArquivo('h3');
        $chave = ChavesDeCobranca::documentoDeCarteira($doc);

        self::assertSame(CategoriaDeArquivo::COBRANCA_DOCUMENTO, $chave->categoria);
        self::assertSame(9, $chave->escopo->tenantIdOuNull());
        self::assertSame('h3', $chave->nome);
    }

    #[TestDox('sem tenant na entidade, as três recusam')]
    public function testSemTenantLanca(): void
    {
        $casos = [
            fn () => ChavesDeCobranca::documentoDeCaso((new CobrancaDocumento())->setCaminhoArquivo('h')),
            fn () => ChavesDeCobranca::documentoDeAcordo((new AcordoDocumento())->setCaminhoArquivo('h')),
            fn () => ChavesDeCobranca::documentoDeCarteira((new CarteiraDocumento())->setCaminhoArquivo('h')),
        ];

        foreach ($casos as $i => $caso) {
            try {
                $caso();
                self::fail(sprintf('caso %d deveria ter recusado entidade sem tenant', $i));
            } catch (ChaveDeArquivoInvalida) {
                self::assertTrue(true);
            }
        }
    }

    #[TestDox('tenant sem id é recusado')]
    public function testTenantSemIdLanca(): void
    {
        $doc = (new CobrancaDocumento())->setTenant(new Tenant())->setCaminhoArquivo('h1');

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeCobranca::documentoDeCaso($doc);
    }

    /** @return iterable<string, array{string}> */
    public static function nomesLegados(): iterable
    {
        yield 'espaço nas bordas' => [' h1.pdf '];
        yield 'termina em ponto'  => ['hash.'];
    }

    #[DataProvider('nomesLegados')]
    #[TestDox('nome preservado byte a byte')]
    public function testNomeEhPreservadoByteAByte(string $nome): void
    {
        $doc = (new CobrancaDocumento())->setTenant($this->tenant(7))->setCaminhoArquivo($nome);

        self::assertSame($nome, ChavesDeCobranca::documentoDeCaso($doc)->nome);
    }

    #[TestDox('as três recebem só a entidade — nenhum Tenant por parâmetro')]
    public function testFabricaNaoAceitaTenantExterno(): void
    {
        foreach (['documentoDeCaso', 'documentoDeAcordo', 'documentoDeCarteira'] as $metodo) {
            $parametros = (new \ReflectionMethod(ChavesDeCobranca::class, $metodo))->getParameters();

            self::assertCount(1, $parametros, $metodo);
            self::assertNotSame(Tenant::class, (string) $parametros[0]->getType(), $metodo);
        }
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }
}
