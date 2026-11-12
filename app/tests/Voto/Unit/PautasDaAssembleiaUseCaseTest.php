<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\PautaInput;
use App\Voto\DTO\RegrasDaPautaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\RegraDeDecisao;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\OpcoesDaPauta;
use App\Voto\UseCase\AdicionarPautaUseCase;
use App\Voto\UseCase\EditarPautaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pautas da assembleia em rascunho (spec voto-assembleia-condominio, Gestor item 3): a pauta nova recebe as opções e a
 * abstenção automática (número 0), na ordem seguinte; editar troca as opções e mantém a abstenção; publicada, nada
 * muda; assembleia de outro escritório não é achada.
 */
#[CoversClass(AdicionarPautaUseCase::class)]
#[CoversClass(EditarPautaUseCase::class)]
#[CoversClass(PautaInput::class)]
final class PautasDaAssembleiaUseCaseTest extends TestCase
{
    #[TestDox('A pauta padrão (atalho) entra com 1 Sim, 2 Não e a abstenção, na ordem seguinte à maior')]
    public function testAdicionaAPautaPadrao(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        new PautaDaAssembleia($assembleia, 3, 'Obra');
        $salva = null;
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->expects(self::once())->method('salvar')->willReturnCallback(static function (PautaDaAssembleia $p, bool $flush) use (&$salva): void {
            self::assertTrue($flush);
            $salva = $p;
        });

        $this->adicionar($tenant, $assembleia, $pautas)->executar($tenant, 7, PautaInput::padrao('Síndico(a)'));

        self::assertInstanceOf(PautaDaAssembleia::class, $salva);
        self::assertSame(4, $salva->getOrdem());
        self::assertSame('Síndico(a)', $salva->getTitulo());
        self::assertSame(
            [[1, 'Sim', false], [2, 'Não', false], [0, 'Abstenção', true]],
            array_map(static fn (OpcaoDaPauta $o): array => [$o->getNumero(), $o->getRotulo(), $o->isAbstencao()], array_values($salva->getOpcoes()->toArray())),
        );
    }

    #[TestDox('Opção inválida não cria a pauta e devolve a mensagem do desenho')]
    public function testOpcaoInvalidaNaoCria(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->expects(self::never())->method('salvar');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Número repetido em Obra.');

        $this->adicionar($tenant, $assembleia, $pautas)->executar($tenant, 7, new PautaInput('Obra', null, [['numero' => '1', 'rotulo' => 'Sim'], ['numero' => '1', 'rotulo' => 'Não']]));
    }

    #[TestDox('Assembleia publicada não recebe pauta nova')]
    public function testPublicadaNaoRecebePauta(): void
    {
        $tenant = new Tenant();
        $assembleia = self::publicada($tenant);
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->expects(self::never())->method('salvar');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('As pautas só mudam enquanto a assembleia está em rascunho.');

        $this->adicionar($tenant, $assembleia, $pautas)->executar($tenant, 7, PautaInput::padrao('Obra'));
    }

    #[TestDox('Assembleia de outro escritório (a busca com o escritório da sessão não acha) dá "não encontrada"')]
    public function testOutroEscritorio(): void
    {
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturn(null);
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->expects(self::never())->method('salvar');

        $this->expectException(AssembleiaNaoEncontradaException::class);

        (new AdicionarPautaUseCase($assembleias, $pautas, new OpcoesDaPauta()))->executar(new Tenant(), 7, PautaInput::padrao('Obra'));
    }

    #[TestDox('Editar troca título, descrição e opções; a abstenção fica, as opções antigas saem pelo repositório')]
    public function testEditaAPauta(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Síndico(a)');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($assembleia);
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->method('buscarDaAssembleia')->with(9, $assembleia)->willReturn($pauta);
        $pautas->expects(self::once())->method('salvar')->with($pauta, true);
        $opcoes = $this->createMock(OpcaoDaPautaRepository::class);
        $opcoes->expects(self::exactly(2))->method('remover');

        (new EditarPautaUseCase($assembleias, $pautas, $opcoes, new OpcoesDaPauta()))->executar($tenant, 7, 9, new PautaInput(
            'Eleição de síndico(a)',
            '  Mandato de 2 anos  ',
            [['numero' => '1', 'rotulo' => 'Chapa Verde'], ['numero' => '2', 'rotulo' => 'Chapa Azul'], ['numero' => '3', 'rotulo' => 'Chapa Branca']],
        ));

        self::assertSame('Eleição de síndico(a)', $pauta->getTitulo());
        self::assertSame('Mandato de 2 anos', $pauta->getDescricao());
        self::assertSame(['Chapa Verde', 'Chapa Azul', 'Chapa Branca'], array_map(static fn (OpcaoDaPauta $o): string => $o->getRotulo(), $pauta->getOpcoesDeVoto()));
        self::assertNotNull($pauta->getAbstencao());
    }

    #[TestDox('Editar pauta de assembleia publicada é recusado antes de tocar nas opções')]
    public function testEditarPublicadaRecusa(): void
    {
        $tenant = new Tenant();
        $assembleia = self::publicada($tenant);
        $pauta = $assembleia->getPautas()->first();
        self::assertInstanceOf(PautaDaAssembleia::class, $pauta);
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturn($assembleia);
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->method('buscarDaAssembleia')->willReturn($pauta);
        $pautas->expects(self::never())->method('salvar');
        $opcoes = $this->createMock(OpcaoDaPautaRepository::class);
        $opcoes->expects(self::never())->method('remover');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('As pautas só mudam enquanto a assembleia está em rascunho.');

        (new EditarPautaUseCase($assembleias, $pautas, $opcoes, new OpcoesDaPauta()))->executar($tenant, 7, 9, PautaInput::padrao('Outra'));
    }

    #[TestDox('Incluir com regras: grava critério, base, vagas, quórum e aceita números do tamanho dos dígitos')]
    public function testIncluiComRegras(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $salva = null;
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->method('salvar')->willReturnCallback(static function (PautaDaAssembleia $p) use (&$salva): void {
            $salva = $p;
        });

        $this->adicionar($tenant, $assembleia, $pautas)->executar($tenant, 7, new PautaInput(
            'Conselho fiscal',
            null,
            [['numero' => '01', 'rotulo' => 'Ana'], ['numero' => '02', 'rotulo' => 'Bia'], ['numero' => '03', 'rotulo' => 'Caio']],
            new RegrasDaPautaInput(RegraDeDecisao::Mais, BaseDaDecisao::Presentes, 2, 2, 50, null),
        ));

        self::assertInstanceOf(PautaDaAssembleia::class, $salva);
        self::assertSame([RegraDeDecisao::Mais, BaseDaDecisao::Presentes, 2, 2, '50.00', null], [
            $salva->getCriterio(), $salva->getBase(), $salva->getDigitos(), $salva->getVagas(), $salva->getQuorumPrimeira(), $salva->getQuorumSegunda(),
        ]);
        self::assertSame([1, 2, 3], array_map(static fn (OpcaoDaPauta $o): int => $o->getNumero(), $salva->getOpcoesDeVoto()));
    }

    #[TestDox('Incluir com regra fora do limite não cria a pauta')]
    public function testIncluiComRegraInvalidaNaoCria(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->expects(self::never())->method('salvar');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('As vagas vão de 1 a 9.');

        $this->adicionar($tenant, $assembleia, $pautas)->executar($tenant, 7, new PautaInput(
            'Obra', null, PautaInput::OPCOES_PADRAO, new RegrasDaPautaInput(vagas: 10),
        ));
    }

    #[TestDox('Editar sem mandar regras mantém as da pauta e confere os números pelos dígitos dela')]
    public function testEditarSemRegrasMantem(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Conselho');
        new OpcaoDaPauta($pauta, 11, 'Ana');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        $pauta->configurar(RegraDeDecisao::Absoluta, BaseDaDecisao::Aptos, 2, 3, 40, 10);
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturn($assembleia);
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->method('buscarDaAssembleia')->willReturn($pauta);
        $opcoes = $this->createMock(OpcaoDaPautaRepository::class);

        (new EditarPautaUseCase($assembleias, $pautas, $opcoes, new OpcoesDaPauta()))->executar($tenant, 7, 9, new PautaInput(
            'Conselho', null, [['numero' => '21', 'rotulo' => 'Bia'], ['numero' => '22', 'rotulo' => 'Caio']],
        ));

        self::assertSame([RegraDeDecisao::Absoluta, BaseDaDecisao::Aptos, 2, 3, '40.00', '10.00'], [
            $pauta->getCriterio(), $pauta->getBase(), $pauta->getDigitos(), $pauta->getVagas(), $pauta->getQuorumPrimeira(), $pauta->getQuorumSegunda(),
        ]);
        self::assertSame([21, 22], array_map(static fn (OpcaoDaPauta $o): int => $o->getNumero(), $pauta->getOpcoesDeVoto()));
    }

    #[TestDox('Editar mandando regras troca as regras da pauta')]
    public function testEditarComRegrasTroca(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Obra');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturn($assembleia);
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->method('buscarDaAssembleia')->willReturn($pauta);

        (new EditarPautaUseCase($assembleias, $pautas, $this->createMock(OpcaoDaPautaRepository::class), new OpcoesDaPauta()))->executar($tenant, 7, 9, new PautaInput(
            'Obra', null, PautaInput::OPCOES_PADRAO, new RegrasDaPautaInput(RegraDeDecisao::DoisTercos, BaseDaDecisao::Aptos, 1, 1, 0, null),
        ));

        self::assertSame([RegraDeDecisao::DoisTercos, BaseDaDecisao::Aptos, '0.00', null], [$pauta->getCriterio(), $pauta->getBase(), $pauta->getQuorumPrimeira(), $pauta->getQuorumSegunda()]);
    }

    #[TestDox('Publicar com mais vagas do que candidaturas é recusado e a assembleia segue em rascunho')]
    public function testPublicarComMaisVagasQueCandidaturas(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Conselho');
        new OpcaoDaPauta($pauta, 1, 'Ana');
        new OpcaoDaPauta($pauta, 2, 'Bia');
        $pauta->configurar(RegraDeDecisao::Mais, BaseDaDecisao::Validos, 1, 3, 50, 0);
        new UnidadeDaAssembleia($assembleia, '101', 'semente', 'hash');

        try {
            $assembleia->publicar();
            self::fail('Devia recusar.');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame('A pauta "Conselho" tem mais vagas (3) do que candidaturas.', $e->getMessage());
        }
        self::assertTrue($assembleia->podeAlterarPautas());
    }

    #[TestDox('Publicar com vagas iguais às candidaturas vale')]
    public function testPublicarComVagasIguaisAsCandidaturas(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Conselho');
        new OpcaoDaPauta($pauta, 1, 'Ana');
        new OpcaoDaPauta($pauta, 2, 'Bia');
        $pauta->configurar(RegraDeDecisao::Mais, BaseDaDecisao::Validos, 1, 2, 50, 0);
        new UnidadeDaAssembleia($assembleia, '101', 'semente', 'hash');

        $assembleia->publicar();

        self::assertFalse($assembleia->podeAlterarPautas());
    }

    private function adicionar(Tenant $tenant, Assembleia $assembleia, PautaDaAssembleiaRepository $pautas): AdicionarPautaUseCase
    {
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($assembleia);

        return new AdicionarPautaUseCase($assembleias, $pautas, new OpcoesDaPauta());
    }

    private static function publicada(Tenant $tenant): Assembleia
    {
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        new UnidadeDaAssembleia($assembleia, '101', 'semente', 'hash');
        $assembleia->publicar();

        return $assembleia;
    }
}
