<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Unit;

use App\Entity\ServiceDesk\Chamado;
use App\Entity\ServiceDesk\ChamadoAnexo;
use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Armazenamento\ChavesDeServiceDesk;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `ChamadoAnexo` não tem tenant próprio: o escritório é o do `Chamado` a que pertence. É o modelo
 * real, e é por ele que a fábrica chega ao escopo — sem inventar atalho.
 */
#[CoversClass(ChavesDeServiceDesk::class)]
final class ChavesDeServiceDeskTest extends TestCase
{
    #[TestDox('anexoDeChamado(): categoria CHAMADO_ANEXO e escopo do tenant do CHAMADO')]
    public function testAnexoUsaOTenantDoChamado(): void
    {
        $chave = ChavesDeServiceDesk::anexoDeChamado($this->anexo($this->tenant(7), 'a.pdf'));

        self::assertSame(CategoriaDeArquivo::CHAMADO_ANEXO, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
    }

    #[TestDox('anexoDeChamado(): anexo sem chamado é recusado')]
    public function testAnexoSemChamadoLanca(): void
    {
        $anexo = (new ChamadoAnexo())->setNomeArquivo('a.pdf');

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeServiceDesk::anexoDeChamado($anexo);
    }

    #[TestDox('anexoDeChamado(): chamado sem tenant é recusado')]
    public function testChamadoSemTenantLanca(): void
    {
        $anexo = (new ChamadoAnexo())->setNomeArquivo('a.pdf')->setChamado(new Chamado());

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeServiceDesk::anexoDeChamado($anexo);
    }

    /** @return iterable<string, array{string}> */
    public static function nomesLegados(): iterable
    {
        yield 'espaço nas bordas' => [' a.pdf '];
        yield 'termina em ponto'  => ['hash.'];
    }

    #[DataProvider('nomesLegados')]
    #[TestDox('anexoDeChamado(): nome preservado byte a byte')]
    public function testNomeEhPreservadoByteAByte(string $nome): void
    {
        $chave = ChavesDeServiceDesk::anexoDeChamado($this->anexo($this->tenant(7), $nome));

        self::assertSame($nome, $chave->nome);
    }

    #[TestDox('anexoDeChamado(): recebe só a entidade')]
    public function testFabricaNaoAceitaTenantExterno(): void
    {
        $parametros = (new \ReflectionMethod(ChavesDeServiceDesk::class, 'anexoDeChamado'))->getParameters();

        self::assertCount(1, $parametros);
        self::assertSame(ChamadoAnexo::class, (string) $parametros[0]->getType());
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function anexo(Tenant $tenant, string $nomeArquivo): ChamadoAnexo
    {
        $chamado = (new Chamado())->setTenant($tenant);

        return (new ChamadoAnexo())->setNomeArquivo($nomeArquivo)->setChamado($chamado);
    }
}
