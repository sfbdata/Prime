<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\DadosDaUnidadeInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\LeitorDaListaDeUnidades;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\Service\TokenDaUnidade;
use App\Voto\UseCase\AdicionarUnidadeUseCase;
use App\Voto\UseCase\EditarUnidadeUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Adicionar e editar unidade: mesma validação e mesma duplicidade da planilha, posse pela assembleia e trava na abertura. */
#[CoversClass(AdicionarUnidadeUseCase::class)]
#[CoversClass(EditarUnidadeUseCase::class)]
#[CoversClass(UnidadeDaAssembleia::class)]
final class AdicionarEEditarUnidadeUseCaseTest extends TestCase
{
    #[TestDox('Adiciona a unidade com o próprio link; situação fora do apto fica não apta')]
    public function testAdiciona(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $salvas = [];
        $unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $unidades->expects(self::once())->method('salvar')->with(self::anything(), true)->willReturnCallback(static function (UnidadeDaAssembleia $u) use (&$salvas): void {
            $salvas[] = $u;
        });
        $tokens = new TokenDaUnidade('segredo-de-teste');

        $this->adicionar($tenant, $assembleia, $unidades, $tokens)->executar(
            $tenant,
            7,
            new DadosDaUnidadeInput('301', 'C', 'inadimplente', '0,125', 'Ana', 'ana@exemplo.com'),
        );

        self::assertCount(1, $salvas);
        self::assertSame('301', $salvas[0]->getIdentificacao());
        self::assertSame('C', $salvas[0]->getBloco());
        self::assertSame('0.125000', $salvas[0]->getFracao());
        self::assertFalse($salvas[0]->isApta());
        self::assertNull($salvas[0]->getTelefone(), 'o telefone só entra pela importação');
        self::assertSame($tokens->hash($tokens->token($salvas[0]->getSemente())), $salvas[0]->getTokenHash());
    }

    /** @return iterable<string, array{DadosDaUnidadeInput, string}> */
    public static function invalidas(): iterable
    {
        yield 'sem unidade' => [new DadosDaUnidadeInput('', 'A', '', '0,1'), 'Linha sem a unidade.'];
        yield 'sem fração no peso por fração' => [new DadosDaUnidadeInput('301', 'A'), 'Informe a fração ideal.'];
        yield 'fração zero' => [new DadosDaUnidadeInput('301', 'A', '', '0'), 'A fração precisa ser maior que zero.'];
        yield 'fração inválida' => [new DadosDaUnidadeInput('301', 'A', '', 'abc'), 'Fração inválida.'];
        yield 'e-mail inválido' => [new DadosDaUnidadeInput('301', 'A', '', '0,1', '', 'x@'), 'E-mail inválido.'];
        yield 'já está na lista (sem caixa)' => [new DadosDaUnidadeInput('101', 'a', '', '0,1'), 'Esta unidade já está na lista.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidas')]
    #[TestDox('Adicionar recusa com o motivo e não grava')]
    public function testAdicionarRecusa(DadosDaUnidadeInput $dados, string $motivo): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        new UnidadeDaAssembleia($assembleia, '101', 's', 'h', 'A', fracao: '0.500000');
        $unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $unidades->expects(self::never())->method('salvar');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage($motivo);

        $this->adicionar($tenant, $assembleia, $unidades, new TokenDaUnidade('s'))->executar($tenant, 7, $dados);
    }

    #[TestDox('Edita: troca os campos, mantém o link e o telefone, recalcula a aptidão e zera o envio se o e-mail mudou')]
    public function testEdita(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $unidade = new UnidadeDaAssembleia($assembleia, '101', 'semente', 'hash', 'A', null, '0.500000', 'Ana', 'ana@exemplo.com', '11999990000');
        $unidade->registrarEnvioDoLink();
        $unidades = $this->unidades($unidade);
        $unidades->expects(self::once())->method('salvar')->with($unidade, true);

        $this->editar($tenant, $assembleia, $unidades)->executar(
            $tenant,
            7,
            5,
            new DadosDaUnidadeInput('101-B', 'A', 'inadimplente', '0,4', 'Bia', 'bia@exemplo.com'),
        );

        self::assertSame('101-B', $unidade->getIdentificacao());
        self::assertSame('0.400000', $unidade->getFracao());
        self::assertFalse($unidade->isApta());
        self::assertSame('Bia', $unidade->getResponsavel());
        self::assertSame('semente', $unidade->getSemente());
        self::assertSame('11999990000', $unidade->getTelefone());
        self::assertNull($unidade->getLinkEnviadoEm(), 'e-mail novo: o link ainda não foi enviado a ele');
    }

    #[TestDox('Editar sem mudar o e-mail mantém o envio; manter a própria unidade não é duplicada')]
    public function testEditaSemMudarEmailNemChave(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $unidade = new UnidadeDaAssembleia($assembleia, '101', 'semente', 'hash', 'A', null, null, null, 'ana@exemplo.com');
        $unidade->registrarEnvioDoLink();

        $this->editar($tenant, $assembleia, $this->unidades($unidade))->executar($tenant, 7, 5, new DadosDaUnidadeInput('101', 'A', 'quite', '', 'Ana', 'ana@exemplo.com'));

        self::assertNotNull($unidade->getLinkEnviadoEm());
        self::assertTrue($unidade->isApta());
    }

    #[TestDox('Editar para a unidade de outra recusa; a unidade fica como estava')]
    public function testEditarParaDuplicada(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        new UnidadeDaAssembleia($assembleia, '102', 's2', 'h2', 'A');
        $unidade = new UnidadeDaAssembleia($assembleia, '101', 's1', 'h1', 'A');
        $unidades = $this->unidades($unidade);
        $unidades->expects(self::never())->method('salvar');

        try {
            $this->editar($tenant, $assembleia, $unidades)->executar($tenant, 7, 5, new DadosDaUnidadeInput('102', 'a'));
            self::fail('Devia recusar a duplicada.');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame('Esta unidade já está na lista.', $e->getMessage());
        }
        self::assertSame('101', $unidade->getIdentificacao());
    }

    #[TestDox('Unidade de outra assembleia ou escritório: não encontrada')]
    public function testUnidadeAlheia(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $unidades->method('buscarDaAssembleia')->willReturn(null);

        $this->expectException(AssembleiaNaoEncontradaException::class);

        $this->editar($tenant, $assembleia, $unidades)->executar($tenant, 7, 99, new DadosDaUnidadeInput('101'));
    }

    #[TestDox('Depois da abertura da primeira pauta, adicionar e editar são recusados')]
    public function testBloqueadas(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        $unidade = new UnidadeDaAssembleia($assembleia, '101', 'semente', 'hash');
        $assembleia->publicar();
        $pauta->abrir('Mesa', 1, null);
        $unidades = $this->unidades($unidade);
        $unidades->expects(self::never())->method('salvar');

        foreach ([
            fn () => $this->adicionar($tenant, $assembleia, $unidades, new TokenDaUnidade('s'))->executar($tenant, 7, new DadosDaUnidadeInput('102')),
            fn () => $this->editar($tenant, $assembleia, $unidades)->executar($tenant, 7, 5, new DadosDaUnidadeInput('103')),
        ] as $acao) {
            try {
                $acao();
                self::fail('Devia recusar depois da abertura.');
            } catch (OperacaoInvalidaNaAssembleiaException $e) {
                self::assertSame('A lista fica bloqueada depois da abertura da votação.', $e->getMessage());
            }
        }
        self::assertSame('101', $unidade->getIdentificacao());
    }

    private function unidades(UnidadeDaAssembleia $unidade): UnidadeDaAssembleiaRepository&\PHPUnit\Framework\MockObject\MockObject
    {
        $unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $unidades->method('buscarDaAssembleia')->with(5)->willReturn($unidade);

        return $unidades;
    }

    private function assembleias(Tenant $tenant, Assembleia $assembleia): AssembleiaRepository
    {
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($assembleia);

        return $assembleias;
    }

    private function adicionar(Tenant $tenant, Assembleia $assembleia, UnidadeDaAssembleiaRepository $unidades, TokenDaUnidade $tokens): AdicionarUnidadeUseCase
    {
        return new AdicionarUnidadeUseCase($this->assembleias($tenant, $assembleia), $unidades, new LeitorDaListaDeUnidades(), $tokens, self::serializacao());
    }

    private function editar(Tenant $tenant, Assembleia $assembleia, UnidadeDaAssembleiaRepository $unidades): EditarUnidadeUseCase
    {
        return new EditarUnidadeUseCase($this->assembleias($tenant, $assembleia), $unidades, new LeitorDaListaDeUnidades(), self::serializacao());
    }

    private static function serializacao(): SerializacaoDaConfiguracao
    {
        return new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore()));
    }
}
