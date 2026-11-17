<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\TipoDaPauta;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\OpcoesDaPauta;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\Service\TransacaoDoVoto;
use App\Voto\UseCase\AdicionarPautaUseCase;
use App\Voto\UseCase\AplicarModeloDePautasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** "Modelos prontos": as pautas entram pelo caminho de "adicionar pauta", em rascunho, na ordem do modelo. */
#[CoversClass(AplicarModeloDePautasUseCase::class)]
final class AplicarModeloDePautasUseCaseTest extends TestCase
{
    use ComFotosDasCandidaturas;

    #[TestDox('AGO completa cria as 4 pautas em sequência, com tipo e regras do desenho, e devolve os títulos')]
    public function testCriaAsPautasDoModelo(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $salvas = [];
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->method('salvar')->willReturnCallback(static function (PautaDaAssembleia $p, bool $flush = false) use (&$salvas): void {
            self::assertFalse($flush, 'o modelo grava de uma vez, na transação');
            $salvas[] = $p;
        });

        $titulos = $this->aplicar($tenant, $assembleia, $pautas)->executar($tenant, 7, 'condominio_ago');

        self::assertSame(['Aprovação de contas', 'Previsão orçamentária', 'Síndico(a)', 'Conselho fiscal'], $titulos);
        self::assertSame([1, 2, 3, 4], array_map(static fn (PautaDaAssembleia $p): int => $p->getOrdem(), $salvas));
        self::assertSame(
            [TipoDaPauta::Deliberacao, TipoDaPauta::Deliberacao, TipoDaPauta::EleicaoDeCargo, TipoDaPauta::EleicaoDeCargo],
            array_map(static fn (PautaDaAssembleia $p): TipoDaPauta => $p->getTipo(), $salvas),
        );
        self::assertSame(2, $salvas[2]->getDigitos());
        self::assertSame([[0, 'Abstenção']], array_map(static fn ($o): array => [$o->getNumero(), $o->getRotulo()], array_values($salvas[2]->getOpcoes()->toArray())), 'eleição entra sem nomes fictícios');
        self::assertCount(3, $salvas[0]->getOpcoes(), 'Sim, Não e a abstenção');
    }

    #[TestDox('Alteração da convenção grava dois terços sobre o total de aptos')]
    public function testConvencaoGravaDoisTercos(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGE', PesoDoVoto::Fracao, 'Gestora');
        $salva = null;
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->method('salvar')->willReturnCallback(static function (PautaDaAssembleia $p, bool $flush = false) use (&$salva): void {
            $salva = $p;
        });

        $this->aplicar($tenant, $assembleia, $pautas)->executar($tenant, 7, 'condominio_age_convencao');

        self::assertInstanceOf(PautaDaAssembleia::class, $salva);
        self::assertSame('dois', $salva->getCriterio()->value);
        self::assertSame('aptos', $salva->getBase()->value);
    }

    #[TestDox('Falha na 3ª pauta do modelo: nada é confirmado (a transação só fecha no fim) e nenhum salvar grava na hora')]
    public function testFalhaNaTerceiraPautaNaoConfirmaNada(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO', PesoDoVoto::Fracao, 'Gestora');
        $chamadas = 0;
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->method('salvar')->willReturnCallback(static function (PautaDaAssembleia $p, bool $flush = false) use (&$chamadas): void {
            self::assertFalse($flush, 'nenhum flush antes do fim');
            if (3 === ++$chamadas) {
                throw new \RuntimeException('falhou na 3ª');
            }
        });
        $transacao = new TransacaoFalsa();

        try {
            $this->aplicar($tenant, $assembleia, $pautas, $transacao)->executar($tenant, 7, 'condominio_ago');
            self::fail('Era para falhar.');
        } catch (\RuntimeException $e) {
            self::assertSame('falhou na 3ª', $e->getMessage());
        }

        self::assertSame(3, $chamadas);
        self::assertSame(0, $transacao->confirmadas, 'a transação não foi confirmada');
        self::assertSame(1, $transacao->desfeitas);
    }

    #[TestDox('Modelo desconhecido é recusado sem gravar nada')]
    public function testModeloDesconhecidoNaoGrava(): void
    {
        $tenant = new Tenant();
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->expects(self::never())->method('salvar');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);

        $this->aplicar($tenant, new Assembleia($tenant, 'AGO', PesoDoVoto::Fracao, 'Gestora'), $pautas)->executar($tenant, 7, 'nao_existe');
    }

    #[TestDox('Modelo de outro cenário que não o da assembleia é recusado sem gravar nada')]
    public function testModeloDeOutroCenarioNaoGrava(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'Eleição', PesoDoVoto::Unidade, 'Gestora');
        $assembleia->definirCenario(\App\Voto\Enum\CenarioDaAssembleia::Classe);
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->expects(self::never())->method('salvar');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Modelo de pautas desconhecido.');

        $this->aplicar($tenant, $assembleia, $pautas)->executar($tenant, 7, 'condominio_ago');
    }

    #[TestDox('Assembleia de outro escritório não é achada e nenhuma pauta é gravada')]
    public function testOutroEscritorioNaoGrava(): void
    {
        $tenant = new Tenant();
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturn(null);
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->expects(self::never())->method('salvar');
        $sut = new AplicarModeloDePautasUseCase(new AdicionarPautaUseCase($assembleias, $pautas, new OpcoesDaPauta(), self::serializacao(), $this->fotosDasCandidaturas()), $assembleias, $pautas, self::serializacao(), new TransacaoFalsa());

        $this->expectException(AssembleiaNaoEncontradaException::class);

        $sut->executar($tenant, 99, 'condominio_ago');
    }

    private function aplicar(Tenant $tenant, Assembleia $assembleia, PautaDaAssembleiaRepository $pautas, ?TransacaoFalsa $transacao = null): AplicarModeloDePautasUseCase
    {
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($assembleia);

        return new AplicarModeloDePautasUseCase(new AdicionarPautaUseCase($assembleias, $pautas, new OpcoesDaPauta(), self::serializacao(), $this->fotosDasCandidaturas()), $assembleias, $pautas, self::serializacao(), $transacao ?? new TransacaoFalsa());
    }

    private static function serializacao(): SerializacaoDaConfiguracao
    {
        return new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore()));
    }
}

/** Transação de teste: conta se o trabalho terminou (confirma) ou falhou (desfaz). */
final class TransacaoFalsa implements TransacaoDoVoto
{
    public int $confirmadas = 0;
    public int $desfeitas = 0;

    public function executar(callable $trabalho): mixed
    {
        try {
            $resultado = $trabalho();
            ++$this->confirmadas;

            return $resultado;
        } catch (\Throwable $e) {
            ++$this->desfeitas;
            throw $e;
        }
    }

    public function gravar(): void
    {
    }
}
