<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A fábrica é o único lugar do domínio Pasta que sabe traduzir entidade em chave (E2.2). O que
 * se prova aqui é o risco R1 da spec: o escopo sai da ENTIDADE persistida, nunca de um parâmetro
 * que possa descasar em silêncio — e o nome vai byte a byte, sem nenhuma "arrumação" (D8).
 */
#[CoversClass(ChavesDePasta::class)]
final class ChavesDePastaTest extends TestCase
{
    #[TestDox('documento(): categoria PASTA_DOCUMENTO')]
    public function testDocumentoUsaACategoriaDeDocumentoDePasta(): void
    {
        $chave = ChavesDePasta::documento($this->documento($this->tenant(7), 'abc.pdf'));

        self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $chave->categoria);
    }

    #[TestDox('documento(): o escopo é o tenant da ENTIDADE')]
    public function testDocumentoTiraOEscopoDoTenantDaEntidade(): void
    {
        $chave = ChavesDePasta::documento($this->documento($this->tenant(7), 'abc.pdf'));

        self::assertSame(7, $chave->escopo->tenantIdOuNull());
        self::assertFalse($chave->escopo->ehGlobal());
    }

    #[TestDox('documento(): sem tenant na entidade, recusa em vez de inventar escopo')]
    public function testDocumentoSemTenantLanca(): void
    {
        $documento = $this->documento(null, 'abc.pdf');

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDePasta::documento($documento);
    }

    #[TestDox('documento(): tenant ainda sem id (não persistido) também é recusado')]
    public function testDocumentoComTenantSemIdLanca(): void
    {
        $documento = $this->documento(new Tenant(), 'abc.pdf');

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDePasta::documento($documento);
    }

    /** @return iterable<string, array{string}> */
    public static function nomesLegadosQueNaoPodemMudar(): iterable
    {
        yield 'espaço nas bordas'    => [' com-espaco.pdf '];
        yield 'termina em ponto'     => ['9f2a1c-sem-extensao.'];
        yield 'acento sem normalizar' => ["ac\u{0327}ai\u{0301}.pdf"];
    }

    #[DataProvider('nomesLegadosQueNaoPodemMudar')]
    #[TestDox('documento(): o nome do banco vai byte a byte para a chave')]
    public function testNomeEhPreservadoByteAByte(string $nome): void
    {
        $chave = ChavesDePasta::documento($this->documento($this->tenant(7), $nome));

        self::assertSame($nome, $chave->nome);
    }

    #[TestDox('documento(): recebe SÓ a entidade — não há parâmetro por onde trocar o escopo')]
    public function testFabricaPorEntidadeNaoAceitaTenantExterno(): void
    {
        $parametros = (new \ReflectionMethod(ChavesDePasta::class, 'documento'))->getParameters();

        self::assertCount(1, $parametros);
        self::assertSame(PastaDocumento::class, (string) $parametros[0]->getType());
    }

    #[TestDox('documentoPorNome(): para projeção escalar já filtrada pelo tenant, o escopo é o informado')]
    public function testDocumentoPorNomeUsaOTenantInformado(): void
    {
        $chave = ChavesDePasta::documentoPorNome(7, 'peca.html');

        self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
        self::assertSame('peca.html', $chave->nome);
    }

    #[TestDox('imagemDoEditor(): categoria com isolamento físico, escopo do tenant da sessão')]
    public function testImagemDoEditorUsaACategoriaComIsolamento(): void
    {
        $chave = ChavesDePasta::imagemDoEditor($this->tenant(7), 'a1b2.png');

        self::assertSame(CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
        self::assertSame('a1b2.png', $chave->nome);
    }

    #[TestDox('imagemDoEditor(): tenant sem id é recusado')]
    public function testImagemDoEditorComTenantSemIdLanca(): void
    {
        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDePasta::imagemDoEditor(new Tenant(), 'a1b2.png');
    }

    // ------------------------------------------------------------------ helpers

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function documento(?Tenant $tenant, string $caminhoArquivo): PastaDocumento
    {
        return (new PastaDocumento())
            ->setTenant($tenant)
            ->setCaminhoArquivo($caminhoArquivo);
    }
}
