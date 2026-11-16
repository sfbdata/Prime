<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\PautaNoPainel;
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
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\Service\TransacaoDoVoto;
use App\Voto\UseCase\ArquivarAssembleiaUseCase;
use App\Voto\UseCase\CancelarPautaUseCase;
use App\Voto\UseCase\DesarquivarAssembleiaUseCase;
use App\Voto\UseCase\DevolverPautaUseCase;
use App\Voto\UseCase\ExcluirRascunhoDaAssembleiaUseCase;
use App\Voto\UseCase\RetirarPautaDaOrdemDoDiaUseCase;
use App\Voto\UseCase\RetomarPautaUseCase;
use App\Voto\UseCase\SuspenderPautaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Os casos de uso do ciclo de vida com os repositórios dublados: a guarda de posse (outro escritório vira "não
 * encontrada"), a pauta relida TRAVADA e a regra da entidade chamada com quem opera. As regras em si estão em
 * CicloDaPautaTest e CicloDaAssembleiaTest.
 */
#[CoversClass(SuspenderPautaUseCase::class)]
#[CoversClass(RetomarPautaUseCase::class)]
#[CoversClass(DevolverPautaUseCase::class)]
#[CoversClass(CancelarPautaUseCase::class)]
#[CoversClass(RetirarPautaDaOrdemDoDiaUseCase::class)]
#[CoversClass(ArquivarAssembleiaUseCase::class)]
#[CoversClass(DesarquivarAssembleiaUseCase::class)]
#[CoversClass(ExcluirRascunhoDaAssembleiaUseCase::class)]
#[CoversClass(AssembleiaNoPainel::class)]
#[CoversClass(PautaNoPainel::class)]
final class CicloDosUseCasesTest extends TestCase
{
    use ComFotosDasCandidaturas;

    private AssembleiaRepository&MockObject $assembleias;
    private PautaDaAssembleiaRepository&MockObject $pautas;
    private TransacaoDoVoto $transacao;
    private Tenant $tenant;
    private Assembleia $assembleia;
    private PautaDaAssembleia $pauta;

    protected function setUp(): void
    {
        $this->assembleias = $this->createMock(AssembleiaRepository::class);
        $this->pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $this->transacao = new class () implements TransacaoDoVoto {
            public function executar(callable $trabalho): mixed
            {
                return $trabalho();
            }

            public function gravar(): void
            {
            }
        };
        $this->tenant = new Tenant();
        $this->assembleia = new Assembleia($this->tenant, 'AGO', PesoDoVoto::Unidade, 'G');
        $this->pauta = new PautaDaAssembleia($this->assembleia, 1, 'Contas');
        new OpcaoDaPauta($this->pauta, 1, 'Sim');
        new OpcaoDaPauta($this->pauta, 2, 'Não');
        new UnidadeDaAssembleia($this->assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));
        $this->assembleia->publicar(new \DateTimeImmutable('-1 hour'));
        $this->assembleias->method('buscarDoEscritorio')->willReturnCallback(
            fn (int $id, Tenant $t): ?Assembleia => 5 === $id && $t === $this->tenant ? $this->assembleia : null,
        );
        $this->pautas->method('buscarTravadaDaAssembleia')->willReturnCallback(
            fn (int $id, Assembleia $a): ?PautaDaAssembleia => 7 === $id ? $this->pauta : null,
        );
    }

    #[TestDox('Suspender, retomar e cancelar a pauta pela transação, com quem opera')]
    public function testSuspenderRetomarCancelar(): void
    {
        $this->pauta->abrir('Mesa', 1, null, new \DateTimeImmutable('-5 minutes'));

        (new SuspenderPautaUseCase($this->assembleias, $this->pautas, $this->transacao))->executar($this->tenant, 5, 7, 'Queda de energia', 'Maria');
        self::assertTrue($this->pauta->suspensa());
        self::assertSame('Maria', $this->pauta->getSuspensaPorNome());

        (new RetomarPautaUseCase($this->assembleias, $this->pautas, $this->transacao))->executar($this->tenant, 5, 7, 'Maria');
        self::assertFalse($this->pauta->suspensa());

        (new CancelarPautaUseCase($this->assembleias, $this->pautas, $this->transacao))->executar($this->tenant, 5, 7, 'Convocação irregular', 'Maria');
        self::assertTrue($this->pauta->cancelada());
        self::assertSame('Maria', $this->pauta->getCanceladaPorNome());
    }

    #[TestDox('Retirar da ordem do dia a pauta que não abriu')]
    public function testRetirar(): void
    {
        (new RetirarPautaDaOrdemDoDiaUseCase($this->assembleias, $this->pautas, $this->transacao))->executar($this->tenant, 5, 7, 'Matéria adiada', 'Maria');

        self::assertTrue($this->pauta->retirada());
        self::assertSame('Maria', $this->pauta->getRetiradaPorNome());

        (new DevolverPautaUseCase($this->assembleias, $this->pautas, $this->transacao))->executar($this->tenant, 5, 7, 'Maria');
        self::assertFalse($this->pauta->retirada());
    }

    #[TestDox('Outro escritório ou pauta inexistente: AssembleiaNaoEncontradaException em todas as ações da pauta')]
    public function testPosse(): void
    {
        $casos = [
            new SuspenderPautaUseCase($this->assembleias, $this->pautas, $this->transacao),
            new RetomarPautaUseCase($this->assembleias, $this->pautas, $this->transacao),
            new CancelarPautaUseCase($this->assembleias, $this->pautas, $this->transacao),
            new RetirarPautaDaOrdemDoDiaUseCase($this->assembleias, $this->pautas, $this->transacao),
            new DevolverPautaUseCase($this->assembleias, $this->pautas, $this->transacao),
        ];
        foreach ($casos as $caso) {
            foreach ([[new Tenant(), 5, 7], [$this->tenant, 6, 7], [$this->tenant, 5, 8]] as [$tenant, $assembleia, $pauta]) {
                try {
                    $caso instanceof RetomarPautaUseCase || $caso instanceof DevolverPautaUseCase
                        ? $caso->executar($tenant, $assembleia, $pauta, 'Maria')
                        : $caso->executar($tenant, $assembleia, $pauta, 'Motivo válido', 'Maria');
                    self::fail($caso::class . ' devia dar "não encontrada".');
                } catch (AssembleiaNaoEncontradaException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        self::assertFalse($this->pauta->retirada());
    }

    #[TestDox('A recusa da regra sobe como OperacaoInvalidaNaAssembleiaException')]
    public function testRecusaSobe(): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Só votação aberta pode ser suspensa.');

        (new SuspenderPautaUseCase($this->assembleias, $this->pautas, $this->transacao))->executar($this->tenant, 5, 7, 'Motivo válido', 'Maria');
    }

    #[TestDox('Arquivar relê as pautas travadas e depois arquiva; desarquivar devolve; outro escritório dá "não encontrada"')]
    public function testArquivarDesarquivar(): void
    {
        $this->pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $this->pautas->expects(self::once())->method('buscarTravadaDaAssembleia')->willReturn($this->pauta);

        (new ArquivarAssembleiaUseCase($this->assembleias, $this->pautas, $this->transacao))->executar($this->tenant, 5, 'Assembleia concluída', 'Maria');
        self::assertTrue($this->assembleia->arquivada());
        self::assertSame('Maria', $this->assembleia->getArquivadaPorNome());

        (new DesarquivarAssembleiaUseCase($this->assembleias, $this->transacao))->executar($this->tenant, 5, 'Maria');
        self::assertFalse($this->assembleia->arquivada());

        $this->expectException(AssembleiaNaoEncontradaException::class);
        (new DesarquivarAssembleiaUseCase($this->assembleias, $this->transacao))->executar(new Tenant(), 5, 'Maria');
    }

    #[TestDox('Arquivar com pauta aberta é recusado e nada é arquivado')]
    public function testArquivarComPautaAberta(): void
    {
        $this->pauta->abrir('Mesa', 1, null, new \DateTimeImmutable('-5 minutes'));

        try {
            (new ArquivarAssembleiaUseCase($this->assembleias, $this->pautas, $this->transacao))->executar($this->tenant, 5, 'Motivo válido', 'Maria');
            self::fail('Devia recusar.');
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame('Encerre a votação antes de arquivar.', $recusa->getMessage());
        }
        self::assertFalse($this->assembleia->arquivada());
    }

    #[TestDox('Excluir rascunho remove com flush; publicada é recusada e não remove; outro escritório dá "não encontrada"')]
    public function testExcluirRascunho(): void
    {
        $serializacao = new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore()));
        $rascunho = new Assembleia($this->tenant, 'Rascunho', PesoDoVoto::Unidade, 'G');
        $repositorio = $this->createMock(AssembleiaRepository::class);
        $repositorio->method('buscarDoEscritorio')->willReturnCallback(
            fn (int $id, Tenant $t): ?Assembleia => $t !== $this->tenant ? null : (1 === $id ? $rascunho : $this->assembleia),
        );
        $repositorio->expects(self::once())->method('remover')->with($rascunho, true);
        $caso = new ExcluirRascunhoDaAssembleiaUseCase($repositorio, $serializacao, $this->fotosDasCandidaturas());

        $caso->executar($this->tenant, 1);

        try {
            $caso->executar($this->tenant, 2);
            self::fail('A publicada não se exclui.');
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame('Só rascunho nunca publicado pode ser excluído. Votação publicada pode ser arquivada.', $recusa->getMessage());
        }
        $this->expectException(AssembleiaNaoEncontradaException::class);
        $caso->executar(new Tenant(), 1);
    }

    #[TestDox('PautaNoPainel: ciclo normal, suspensa, cancelada e retirada; retirada não conta como aguardando a mesa')]
    public function testPautaNoPainel(): void
    {
        $normal = self::pautaNoPainel(EstadoDaPauta::Aberta);
        $suspensa = self::pautaNoPainel(EstadoDaPauta::Aberta, suspensaEm: new \DateTimeImmutable());
        $cancelada = self::pautaNoPainel(EstadoDaPauta::Encerrada, canceladaEm: new \DateTimeImmutable());
        $retirada = self::pautaNoPainel(EstadoDaPauta::Aguardando, retiradaEm: new \DateTimeImmutable());

        self::assertSame(['normal', 'suspensa', 'cancelada', 'retirada'], [$normal->ciclo(), $suspensa->ciclo(), $cancelada->ciclo(), $retirada->ciclo()]);
        self::assertTrue($suspensa->aberta(), 'a chip "aberta" continua valendo na suspensa');
        self::assertFalse($retirada->aguardandoAMesa());
    }

    #[TestDox('AssembleiaNoPainel: retirada fica fora da situação e do resultado; cancelada vai para a lista de canceladas; arquivada e excluível')]
    public function testAssembleiaNoPainel(): void
    {
        $encerrada = self::pautaNoPainel(EstadoDaPauta::Encerrada);
        $cancelada = self::pautaNoPainel(EstadoDaPauta::Encerrada, canceladaEm: new \DateTimeImmutable());
        $retirada = self::pautaNoPainel(EstadoDaPauta::Aguardando, retiradaEm: new \DateTimeImmutable());

        $painel = self::assembleiaNoPainel(EstadoDaAssembleia::Publicada, [$encerrada, $cancelada, $retirada]);

        self::assertSame('encerrada', $painel->situacao(), 'a retirada não segura a assembleia em "publicada"');
        self::assertSame([$encerrada], $painel->pautasEncerradas());
        self::assertSame([$cancelada], $painel->pautasCanceladas());
        self::assertSame([$retirada], $painel->pautasRetiradas());
        self::assertFalse($painel->arquivada());
        self::assertFalse($painel->podeExcluir());
        self::assertTrue(self::assembleiaNoPainel(EstadoDaAssembleia::Rascunho, [])->podeExcluir());
        self::assertTrue(self::assembleiaNoPainel(EstadoDaAssembleia::Publicada, [], new \DateTimeImmutable())->arquivada());
    }

    private static function pautaNoPainel(EstadoDaPauta $estado, ?\DateTimeImmutable $suspensaEm = null, ?\DateTimeImmutable $canceladaEm = null, ?\DateTimeImmutable $retiradaEm = null): PautaNoPainel
    {
        return new PautaNoPainel(
            id: 1,
            ordem: 1,
            titulo: 'Contas',
            descricao: null,
            estado: $estado,
            assembleiaPublicada: true,
            convocacao: null,
            quorumExigido: null,
            abertaEm: null,
            abertaPorNome: null,
            prazoAte: null,
            encerradaEm: null,
            encerradaPorNome: null,
            motivoEncerramento: null,
            opcoes: [],
            votantes: 0,
            pesoVotante: '0.000000',
            unidadesAptas: 0,
            pesoApto: '0.000000',
            resultado: null,
            suspensaEm: $suspensaEm,
            canceladaEm: $canceladaEm,
            retiradaEm: $retiradaEm,
        );
    }

    /** @param list<PautaNoPainel> $pautas */
    private static function assembleiaNoPainel(EstadoDaAssembleia $estado, array $pautas, ?\DateTimeImmutable $arquivadaEm = null): AssembleiaNoPainel
    {
        return new AssembleiaNoPainel(
            id: 1,
            titulo: 'AGO',
            peso: PesoDoVoto::Unidade,
            estado: $estado,
            criadaPorNome: 'G',
            criadaEm: new \DateTimeImmutable(),
            publicadaEm: EstadoDaAssembleia::Publicada === $estado ? new \DateTimeImmutable() : null,
            podeAlterarPautas: false,
            podeAlterarUnidades: false,
            unidadesAptas: 0,
            unidadesInaptas: 0,
            pesoApto: '0.000000',
            pautas: $pautas,
            arquivadaEm: $arquivadaEm,
        );
    }
}
