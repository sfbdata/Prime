<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Unit;

use App\Entity\Tenant\Tenant;
use App\Kanban\Armazenamento\ChavesDeKanban;
use App\Kanban\Entity\KanbanAnexo;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `KanbanAnexo` guarda só o NOME do arquivo em `caminho` (é o retorno de `salvar()`), e carrega o
 * tenant copiado do card na construção. A fábrica lê os dois da entidade — e nada mais.
 *
 * A entidade é dublada porque construí-la de verdade exige card → coluna → board → tenant; o que
 * se prova aqui é só a tradução dos getters em chave.
 */
#[CoversClass(ChavesDeKanban::class)]
final class ChavesDeKanbanTest extends TestCase
{
    #[TestDox('anexo(): categoria KANBAN_ANEXO e escopo do tenant da entidade')]
    public function testAnexoUsaCategoriaEEscopoDaEntidade(): void
    {
        $chave = ChavesDeKanban::anexo($this->anexo($this->tenant(7), 'x.png'));

        self::assertSame(CategoriaDeArquivo::KANBAN_ANEXO, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
        self::assertSame('x.png', $chave->nome);
    }

    #[TestDox('anexo(): sem tenant, recusa')]
    public function testAnexoSemTenantLanca(): void
    {
        $anexo = $this->anexo(null, 'x.png');

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeKanban::anexo($anexo);
    }

    #[TestDox('anexo(): tenant sem id é recusado')]
    public function testAnexoComTenantSemIdLanca(): void
    {
        $anexo = $this->anexo(new Tenant(), 'x.png');

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeKanban::anexo($anexo);
    }

    /** @return iterable<string, array{string}> */
    public static function nomesLegados(): iterable
    {
        yield 'espaço nas bordas' => [' x.png '];
        yield 'termina em ponto'  => ['hash.'];
    }

    #[DataProvider('nomesLegados')]
    #[TestDox('anexo(): nome preservado byte a byte')]
    public function testNomeEhPreservadoByteAByte(string $nome): void
    {
        self::assertSame($nome, ChavesDeKanban::anexo($this->anexo($this->tenant(7), $nome))->nome);
    }

    #[TestDox('anexo(): recebe só a entidade')]
    public function testFabricaNaoAceitaTenantExterno(): void
    {
        $parametros = (new \ReflectionMethod(ChavesDeKanban::class, 'anexo'))->getParameters();

        self::assertCount(1, $parametros);
        self::assertSame(KanbanAnexo::class, (string) $parametros[0]->getType());
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function anexo(?Tenant $tenant, string $caminho): KanbanAnexo
    {
        $anexo = $this->createStub(KanbanAnexo::class);
        $anexo->method('getTenant')->willReturn($tenant);
        $anexo->method('getCaminho')->willReturn($caminho);

        return $anexo;
    }
}
