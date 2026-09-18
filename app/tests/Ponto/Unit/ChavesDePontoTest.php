<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Tenant;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Entity\JustificativaPonto;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Risco ALTO (ponto eletrônico). Duas formas, e a diferença importa: `anexoDeJustificativa()` lê
 * o nome do getter; `anexoDeJustificativaPorNome()` recebe o nome de fora — para o lote, cujo
 * anexo antigo sai do banco por projeção escalar sob a trava (o getter pode estar velho) e cujo
 * anexo novo ainda não foi persistido no rollback. Nas duas o ESCOPO sai da entidade dona.
 */
#[CoversClass(ChavesDePonto::class)]
final class ChavesDePontoTest extends TestCase
{
    #[TestDox('anexoDeJustificativa(): categoria JUSTIFICATIVA_ANEXO e escopo do tenant da entidade')]
    public function testAnexoUsaCategoriaEEscopoDaEntidade(): void
    {
        $chave = ChavesDePonto::anexoDeJustificativa($this->justificativa($this->tenant(7), 'atestado.pdf'));

        self::assertSame(CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
        self::assertSame('atestado.pdf', $chave->nome);
    }

    #[TestDox('anexoDeJustificativa(): justificativa sem anexo não tem chave')]
    public function testJustificativaSemAnexoLanca(): void
    {
        $justificativa = $this->justificativa($this->tenant(7), null);

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDePonto::anexoDeJustificativa($justificativa);
    }

    #[TestDox('anexoDeJustificativa(): sem tenant na entidade, recusa')]
    public function testSemTenantLanca(): void
    {
        $justificativa = (new JustificativaPonto())->setAnexoPath('atestado.pdf');

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDePonto::anexoDeJustificativa($justificativa);
    }

    #[TestDox('anexoDeJustificativa(): tenant sem id é recusado')]
    public function testTenantSemIdLanca(): void
    {
        $justificativa = $this->justificativa(new Tenant(), 'atestado.pdf');

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDePonto::anexoDeJustificativa($justificativa);
    }

    /** @return iterable<string, array{string}> */
    public static function nomesLegados(): iterable
    {
        yield 'espaço nas bordas' => [' atestado.pdf '];
        yield 'termina em ponto'  => ['hash.'];
    }

    #[DataProvider('nomesLegados')]
    #[TestDox('anexoDeJustificativa(): nome preservado byte a byte')]
    public function testNomeEhPreservadoByteAByte(string $nome): void
    {
        $chave = ChavesDePonto::anexoDeJustificativa($this->justificativa($this->tenant(7), $nome));

        self::assertSame($nome, $chave->nome);
    }

    #[TestDox('anexoDeJustificativaPorNome(): escopo da DONA, nome vindo de fora')]
    public function testPorNomeUsaOEscopoDaDonaEONomeInformado(): void
    {
        $dona  = $this->justificativa($this->tenant(7), 'atual.pdf');
        $chave = ChavesDePonto::anexoDeJustificativaPorNome($dona, 'antigo-no-banco.pdf');

        self::assertSame(CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
        self::assertSame('antigo-no-banco.pdf', $chave->nome);
    }

    #[TestDox('anexoDeJustificativaPorNome(): dona sem tenant é recusada')]
    public function testPorNomeSemTenantLanca(): void
    {
        $dona = (new JustificativaPonto())->setAnexoPath('atual.pdf');

        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDePonto::anexoDeJustificativaPorNome($dona, 'antigo.pdf');
    }

    #[TestDox('nenhuma das duas formas aceita um Tenant por parâmetro')]
    public function testFabricaNaoAceitaTenantExterno(): void
    {
        foreach (['anexoDeJustificativa', 'anexoDeJustificativaPorNome'] as $metodo) {
            foreach ((new \ReflectionMethod(ChavesDePonto::class, $metodo))->getParameters() as $parametro) {
                self::assertNotSame(Tenant::class, (string) $parametro->getType(), $metodo);
            }
        }
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function justificativa(Tenant $tenant, ?string $anexo): JustificativaPonto
    {
        return (new JustificativaPonto())->setTenant($tenant)->setAnexoPath($anexo);
    }
}
