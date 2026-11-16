<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Repository\VotoNominalRepository;
use App\Voto\Service\CnpjDaEntidade;
use App\Voto\Service\ConfiguracaoDaPublicacao;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\Service\TransacaoDoVoto;
use App\Voto\UseCase\CriarAssembleiaUseCase;
use App\Voto\UseCase\ExcluirSimulacaoDaAssembleiaUseCase;
use App\Voto\UseCase\ReiniciarSimulacaoDaAssembleiaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Votação de simulação (W2-4, desenho 18): a marca só em rascunho, o hash só com a marca verdadeira, o reiniciar que volta
 * ao estado de antes de publicar, o apagar, e a recusa em votação real (nenhum repositório é tocado).
 */
#[CoversClass(Assembleia::class)]
#[CoversClass(PautaDaAssembleia::class)]
#[CoversClass(UnidadeDaAssembleia::class)]
#[CoversClass(ConfiguracaoDaPublicacao::class)]
#[CoversClass(ReiniciarSimulacaoDaAssembleiaUseCase::class)]
#[CoversClass(ExcluirSimulacaoDaAssembleiaUseCase::class)]
#[CoversClass(CriarAssembleiaUseCase::class)]
final class SimulacaoDaAssembleiaTest extends TestCase
{
    use ComFotosDasCandidaturas;

    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
    }

    #[TestDox('A marca só muda em rascunho; publicada não vira real nem simulação')]
    public function testMarcaSoEmRascunho(): void
    {
        $assembleia = $this->assembleia();
        self::assertFalse($assembleia->isSimulacao());

        $assembleia->definirSimulacao(true);
        self::assertTrue($assembleia->isSimulacao());
        $assembleia->publicar();

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $assembleia->definirSimulacao(false);
    }

    #[TestDox('Hash: a marca entra só quando verdadeira (o código da assembleia real não muda)')]
    public function testHashSoComMarcaVerdadeira(): void
    {
        $real = $this->assembleia();
        $semMarca = ConfiguracaoDaPublicacao::codigo($real);
        $real->definirSimulacao(false);
        self::assertSame($semMarca, ConfiguracaoDaPublicacao::codigo($real), 'falso não altera o código');

        $real->definirSimulacao(true);
        self::assertNotSame($semMarca, ConfiguracaoDaPublicacao::codigo($real), 'verdadeiro entra no código');
    }

    #[TestDox('Simulação não se arquiva')]
    public function testSimulacaoNaoSeArquiva(): void
    {
        $assembleia = $this->assembleia(simulacao: true);
        $assembleia->publicar();

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Simulação não se arquiva');
        $assembleia->arquivar('Motivo qualquer', 'Maria');
    }

    #[TestDox('Reiniciar: volta a rascunho, sem data de publicação, pautas aguardando e unidades sem link enviado')]
    public function testReiniciarVoltaAoEstadoDeAntes(): void
    {
        $assembleia = $this->assembleia(simulacao: true);
        $assembleia->publicar(new \DateTimeImmutable('-1 hour'));
        $pauta = $assembleia->getPautas()->first();
        $unidade = $assembleia->getUnidades()->first();
        self::assertInstanceOf(PautaDaAssembleia::class, $pauta);
        self::assertInstanceOf(UnidadeDaAssembleia::class, $unidade);
        $pauta->abrir('Mesa', 2, new \DateTimeImmutable('+1 day'));
        $unidade->registrarEnvioDoLink();
        $quantidadeDeOpcoes = \count($pauta->getOpcoes());

        $assembleia->reiniciarSimulacao();

        self::assertSame(EstadoDaAssembleia::Rascunho, $assembleia->getEstado());
        self::assertNull($assembleia->getPublicadaEm());
        self::assertTrue($assembleia->podeAlterarPautas());
        self::assertSame(EstadoDaPauta::Aguardando, $pauta->getEstado());
        self::assertNull($pauta->getAbertaEm());
        self::assertNull($pauta->getPrazoAte());
        self::assertNull($pauta->getConvocacao());
        self::assertSame(0, $pauta->getNulosUnidades());
        self::assertNull($unidade->getLinkEnviadoEm());
        self::assertCount($quantidadeDeOpcoes, $pauta->getOpcoes(), 'a configuração fica');
        self::assertTrue($assembleia->isSimulacao(), 'continua simulação');
    }

    #[TestDox('Reiniciar e apagar recusam a assembleia real, sem mexer em nada')]
    public function testRealRecusaNaEntidade(): void
    {
        $real = $this->assembleia();
        $real->publicar();

        foreach ([static fn () => $real->reiniciarSimulacao(), static fn () => $real->exigirSimulacao('Só votação de simulação pode ser apagada.')] as $acao) {
            try {
                $acao();
                self::fail('Devia recusar.');
            } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
                self::assertStringContainsString('Só votação de simulação', $recusa->getMessage());
            }
        }
        self::assertSame(EstadoDaAssembleia::Publicada, $real->getEstado());
        self::assertNotNull($real->getPublicadaEm());
    }

    #[TestDox('UseCase de reiniciar: em simulação limpa votos, participações, contadores e nulos na transação, com as pautas travadas')]
    public function testUseCaseReiniciaSimulacao(): void
    {
        $simulacao = $this->assembleia(simulacao: true);
        $simulacao->publicar(new \DateTimeImmutable('-1 hour'));
        [$assembleias, $pautas, $participacoes, $nominais, $opcoes] = $this->repositorios($simulacao);
        $pautas->expects(self::once())->method('buscarTravadaDaAssembleia');
        $participacoes->expects(self::once())->method('apagarDaAssembleia')->with($simulacao);
        $nominais->expects(self::once())->method('apagarDaAssembleia')->with($simulacao);
        $opcoes->expects(self::once())->method('zerarDaAssembleia')->with($simulacao);
        $pautas->expects(self::once())->method('zerarNulosDaAssembleia')->with($simulacao);

        $this->reiniciar($assembleias, $pautas, $participacoes, $nominais, $opcoes)->executar($this->tenant, 5);

        self::assertSame(EstadoDaAssembleia::Rascunho, $simulacao->getEstado());
    }

    #[TestDox('UseCase de reiniciar: assembleia real é recusada e NENHUM voto é apagado; outro escritório dá "não encontrada"')]
    public function testUseCaseNuncaReiniciaReal(): void
    {
        $real = $this->assembleia();
        $real->publicar(new \DateTimeImmutable('-1 hour'));
        [$assembleias, $pautas, $participacoes, $nominais, $opcoes] = $this->repositorios($real);
        $pautas->expects(self::never())->method('buscarTravadaDaAssembleia');
        $participacoes->expects(self::never())->method('apagarDaAssembleia');
        $nominais->expects(self::never())->method('apagarDaAssembleia');
        $opcoes->expects(self::never())->method('zerarDaAssembleia');
        $pautas->expects(self::never())->method('zerarNulosDaAssembleia');
        $caso = $this->reiniciar($assembleias, $pautas, $participacoes, $nominais, $opcoes);

        try {
            $caso->executar($this->tenant, 5);
            self::fail('A votação real não se reinicia.');
        } catch (OperacaoInvalidaNaAssembleiaException) {
            self::assertSame(EstadoDaAssembleia::Publicada, $real->getEstado());
        }
        $this->expectException(AssembleiaNaoEncontradaException::class);
        $caso->executar(new Tenant(), 5);
    }

    #[TestDox('UseCase de apagar: remove a simulação; a real é recusada e não é removida; outro escritório dá "não encontrada"')]
    public function testUseCaseApagaSoSimulacao(): void
    {
        $simulacao = $this->assembleia(simulacao: true);
        $real = $this->assembleia();
        $repositorio = $this->createMock(AssembleiaRepository::class);
        $repositorio->method('buscarDoEscritorio')->willReturnCallback(
            fn (int $id, Tenant $t): ?Assembleia => $t !== $this->tenant ? null : (1 === $id ? $simulacao : $real),
        );
        $repositorio->expects(self::once())->method('remover')->with($simulacao, true);
        $caso = new ExcluirSimulacaoDaAssembleiaUseCase($repositorio, new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore())), $this->fotosDasCandidaturas());

        $caso->executar($this->tenant, 1);
        try {
            $caso->executar($this->tenant, 2);
            self::fail('A votação real não se apaga.');
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame('Só votação de simulação pode ser apagada.', $recusa->getMessage());
        }
        $this->expectException(AssembleiaNaoEncontradaException::class);
        $caso->executar(new Tenant(), 1);
    }

    #[TestDox('Criar com a marca grava a simulação em rascunho')]
    public function testCriarMarcada(): void
    {
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $criada = null;
        $assembleias->method('salvar')->willReturnCallback(static function (Assembleia $a) use (&$criada): void {
            $criada = $a;
        });
        $cnpjs = new CnpjDaEntidade($this->createStub(ValidatorInterface::class));

        (new CriarAssembleiaUseCase($assembleias, $cnpjs))->executar($this->tenant, new AssembleiaInput('Treino', 'unidade', simulacao: true), 'Maria');

        self::assertInstanceOf(Assembleia::class, $criada);
        self::assertTrue($criada->isSimulacao());
    }

    private function assembleia(bool $simulacao = false): Assembleia
    {
        $assembleia = new Assembleia($this->tenant, 'AGO', PesoDoVoto::Unidade, 'Gestora');
        $assembleia->definirSimulacao($simulacao);
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));

        return $assembleia;
    }

    /** @return array{AssembleiaRepository&MockObject, PautaDaAssembleiaRepository&MockObject, ParticipacaoNaPautaRepository&MockObject, VotoNominalRepository&MockObject, OpcaoDaPautaRepository&MockObject} */
    private function repositorios(Assembleia $assembleia): array
    {
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturnCallback(
            fn (int $id, Tenant $t): ?Assembleia => 5 === $id && $t === $this->tenant ? $assembleia : null,
        );

        return [$assembleias, $this->createMock(PautaDaAssembleiaRepository::class), $this->createMock(ParticipacaoNaPautaRepository::class), $this->createMock(VotoNominalRepository::class), $this->createMock(OpcaoDaPautaRepository::class)];
    }

    private function reiniciar(AssembleiaRepository $assembleias, PautaDaAssembleiaRepository $pautas, ParticipacaoNaPautaRepository $participacoes, VotoNominalRepository $nominais, OpcaoDaPautaRepository $opcoes): ReiniciarSimulacaoDaAssembleiaUseCase
    {
        $transacao = new class () implements TransacaoDoVoto {
            public function executar(callable $trabalho): mixed
            {
                return $trabalho();
            }

            public function gravar(): void
            {
            }
        };

        return new ReiniciarSimulacaoDaAssembleiaUseCase($assembleias, $pautas, $participacoes, $nominais, $opcoes, $transacao, new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore())));
    }
}
