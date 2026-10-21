<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\Cadeado;

use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\NivelNoCadeado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(NivelNoCadeado::class)]
#[CoversClass(AcessoAPasta::class)]
final class NivelNoCadeadoTest extends TestCase
{
    use CriaCenarioDoCadeadoTrait;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    #[TestDox('pasta sem cadeado: quem é da equipe fica em Editar')]
    public function testSemCadeado(): void
    {
        $pasta = $this->pasta();

        self::assertSame(NivelDeAcesso::Editar, $this->acessoAPasta()->nivel($this->pessoa('Ana Equipe'), $pasta));
    }

    #[TestDox('sem vínculo ativo com o escritório da pasta: Oculto, mesmo sem cadeado')]
    public function testSemVinculo(): void
    {
        $pasta = $this->pasta();

        self::assertSame(NivelDeAcesso::Oculto, $this->acessoAPasta()->nivel($this->pessoa('Fora Daqui', comVinculo: false), $pasta));
    }

    #[TestDox('cadeado Todos: a equipe fica Oculta; primário e Master ficam em Editar (K1)')]
    public function testTodos(): void
    {
        $primario = $this->pessoa('Paula Primária', primario: true);
        $pasta    = $this->pasta();
        $this->gravarCadeado(new CadeadoDaPasta($this->tenant, $pasta, ModoDoCadeado::Todos, $primario, new \DateTimeImmutable()));
        $acesso = $this->acessoAPasta();

        self::assertSame(NivelDeAcesso::Oculto, $acesso->nivel($this->pessoa('Ana Equipe'), $pasta));
        self::assertSame(NivelDeAcesso::Editar, $acesso->nivel($primario, $pasta));
        self::assertSame(NivelDeAcesso::Editar, $acesso->nivel($this->pessoa('Mário Master', master: true, comVinculo: false), $pasta));
    }

    #[TestDox('cadeado por pessoa: o nível gravado de cada um; quem não está na lista, inclusive quem entrou depois, fica Oculto')]
    public function testPorPessoa(): void
    {
        $primario = $this->pessoa('Paula Primária', primario: true);
        $vera     = $this->pessoa('Vera Visualiza');
        $edu      = $this->pessoa('Edu Edita');
        $pasta    = $this->pasta();
        $cadeado  = new CadeadoDaPasta($this->tenant, $pasta, ModoDoCadeado::PorPessoa, $primario, new \DateTimeImmutable());
        $cadeado->definirPorPessoa([[$vera, NivelDeAcesso::Visualizar], [$edu, NivelDeAcesso::Editar]], $primario, new \DateTimeImmutable());
        $this->gravarCadeado($cadeado);
        $novato = $this->pessoa('Nico Novato');
        $acesso = $this->acessoAPasta();

        self::assertSame(NivelDeAcesso::Visualizar, $acesso->nivel($vera, $pasta));
        self::assertSame(NivelDeAcesso::Editar, $acesso->nivel($edu, $pasta));
        self::assertSame(NivelDeAcesso::Oculto, $acesso->nivel($novato, $pasta));
    }

    #[TestDox('primário de OUTRO escritório não é primário aqui: segue a regra da equipe (sem vínculo, Oculto)')]
    public function testPrimarioDeOutroEscritorio(): void
    {
        $pasta = $this->pasta();
        $this->gravarCadeado(new CadeadoDaPasta($this->tenant, $pasta, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));

        self::assertSame(NivelDeAcesso::Oculto, $this->acessoAPasta()->nivel($this->pessoa('Primário Alheio', comVinculo: false), $pasta));
    }

    #[TestDox('níveis em lote: uma consulta de cadeados para as N pastas, indexada pelo id da pasta')]
    public function testNiveisEmLote(): void
    {
        $ana  = $this->pessoa('Ana Equipe');
        $livre = $this->pasta('1');
        $todos = $this->pasta('2');
        $cadeadoTodos = new CadeadoDaPasta($this->tenant, $todos, ModoDoCadeado::Todos, null, new \DateTimeImmutable());
        $this->cadeadoRepository->expects(self::once())->method('dasPastas')
            ->willReturn([(int) $todos->getId() => $cadeadoTodos]);
        $this->cadeadoRepository->expects(self::never())->method('daPastaPorId');

        $niveis = $this->acessoAPasta()->niveisEmLote($ana, [$livre, $todos]);

        self::assertSame([(int) $livre->getId() => NivelDeAcesso::Editar, (int) $todos->getId() => NivelDeAcesso::Oculto], $niveis);
    }

    #[TestDox('memória por requisição: o mesmo nível de novo não consulta outra vez; reset() esquece')]
    public function testMemoria(): void
    {
        $ana   = $this->pessoa('Ana Equipe');
        $pasta = $this->pasta();
        $this->cadeadoRepository->expects(self::exactly(2))->method('daPastaPorId')->willReturn(null);
        $nivel = $this->nivelNoCadeado();

        $nivel->nivel($ana, $pasta);
        $nivel->nivel($ana, $pasta);
        $nivel->reset();
        $nivel->nivel($ana, $pasta);
    }

    #[TestDox('podeVer e podeEditar são o canAccessResource completo da pasta (view e edit), com o teto já dentro')]
    public function testPodeVerEPodeEditarPerguntamAoPermissionChecker(): void
    {
        $ana   = $this->pessoa('Ana Equipe');
        $pasta = $this->pasta();
        $this->permissionChecker->method('canAccessResource')
            ->willReturnCallback(static fn ($u, $t, string $tipo, int $id, string $acao): bool => $tipo === 'pasta' && $id === $pasta->getId() && $acao === 'view');

        self::assertTrue($this->acessoAPasta()->podeVer($ana, $pasta));
        self::assertFalse($this->acessoAPasta()->podeEditar($ana, $pasta));
    }
}
