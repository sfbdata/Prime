<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\RegistrarVotoInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\CedulaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\TokenDaUnidade;
use App\Voto\Service\TransacaoDoVoto;
use App\Voto\Service\UnidadeDoLink;
use App\Voto\UseCase\RegistrarVotoUseCase;
use Doctrine\DBAL\Driver\Exception as ExcecaoDoDriver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O voto do morador (spec docs/specs/voto-assembleia-condominio.md, seções 1 e 2), com os repositórios do domínio
 * dublados (e a transação por uma falsa que anota os passos): as recusas, a ordem INSERT antes do contador, o peso
 * somado e o que a transação confirma ou desfaz. A trava, o UNIQUE e o SQL do
 * contador contra o banco ficam em tests/Voto/Functional/RegistrarVotoNoBancoTest.php.
 */
#[CoversClass(RegistrarVotoUseCase::class)]
#[CoversClass(UnidadeDoLink::class)]
#[Group('voto')]
final class RegistrarVotoUseCaseTest extends TestCase
{
    private const SEGREDO = 'segredo-de-teste';
    private const TOKEN = 'abcdef0123456789abcdef0123456789abcdef01';

    private PautaDaAssembleiaRepository&MockObject $pautas;
    private OpcaoDaPautaRepository&MockObject $opcoes;
    private ParticipacaoNaPautaRepository&MockObject $participacoes;
    private UnidadeDaAssembleiaRepository&MockObject $unidades;
    private LiberacaoDoVotoRepository&MockObject $liberacoes;
    private ?\Throwable $falhaAoGravar = null;
    private MockClock $relogio;
    private Tenant $tenant;

    /** @var list<string> o que aconteceu, na ordem: begin, insert, gravar, somar, flush, commit, rollback */
    private array $passos = [];
    /** @var list<array{list<OpcaoDaPauta>, string}> */
    private array $somados = [];
    /** @var list<ParticipacaoNaPauta> */
    private array $participacoesSalvas = [];

    protected function setUp(): void
    {
        $this->pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $this->opcoes = $this->createMock(OpcaoDaPautaRepository::class);
        $this->participacoes = $this->createMock(ParticipacaoNaPautaRepository::class);
        $this->unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $this->liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $this->relogio = new MockClock('2026-11-10 19:00:00');
        $this->tenant = new Tenant();

        $this->participacoes->method('salvar')->willReturnCallback(function (ParticipacaoNaPauta $p): void {
            $this->passos[] = 'insert';
            $this->participacoesSalvas[] = $p;
        });
        $this->opcoes->method('somarVotos')->willReturnCallback(function (array $escolhidas, string $peso): void {
            $this->passos[] = 'somar';
            $this->somados[] = [$escolhidas, $peso];
        });
        $this->opcoes->method('buscarDaPauta')->willReturnCallback(
            static function (int $id, PautaDaAssembleia $pauta): ?OpcaoDaPauta {
                foreach ($pauta->getOpcoes() as $opcao) {
                    if ($opcao->getId() === $id) {
                        return $opcao;
                    }
                }

                return null;
            },
        );
        $this->liberarOEscritorio(true);
    }

    #[TestDox('Voto registrado: participação (unidade, pauta, hora) gravada ANTES de somar a fração da unidade na opção')]
    public function testRegistraOVotoComAFracao(): void
    {
        [$unidade, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.025000');
        $sim = $this->opcao($pauta, 1);

        $this->caso()->executar(RegistrarVotoInput::deUma(self::TOKEN, 10, (int) $sim->getId()));

        self::assertSame(['begin', 'insert', 'gravar', 'somar', 'flush', 'commit'], $this->passos);
        self::assertSame([[[$sim], '0.025000']], $this->somados);
        self::assertCount(1, $this->participacoesSalvas);
        self::assertSame($unidade, $this->participacoesSalvas[0]->getUnidade());
        self::assertSame($pauta, $this->participacoesSalvas[0]->getPauta());
        self::assertEquals($this->relogio->now(), $this->participacoesSalvas[0]->getRegistradaEm());
    }

    #[TestDox('Uma unidade, um voto: soma peso 1 mesmo com fração cadastrada')]
    public function testPesoPorUnidade(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Unidade, '0.025000');
        $nao = $this->opcao($pauta, 2);

        $this->caso()->executar(RegistrarVotoInput::deUma(self::TOKEN, 10, (int) $nao->getId()));

        self::assertSame([[[$nao], '1.000000']], $this->somados);
    }

    #[TestDox('Abstenção é uma opção da pauta: soma no contador dela, com o peso da unidade')]
    public function testAbstencao(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.031000');
        $abstencao = $pauta->getAbstencao();
        self::assertNotNull($abstencao);

        $this->caso()->executar(RegistrarVotoInput::deUma(self::TOKEN, 10, (int) $abstencao->getId()));

        self::assertSame([[[$abstencao], '0.031000']], $this->somados);
    }

    #[TestDox('Voto duplo: "Você já votou nesta pauta.", nada gravado nem somado, transação desfeita')]
    public function testVotoDuplo(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.025000');
        $this->participacoes->method('jaVotou')->willReturn(true);

        $this->assertRecusa(RegistrarVotoUseCase::JA_VOTOU, (int) $this->opcao($pauta, 1)->getId());

        self::assertSame(['begin', 'rollback'], $this->passos);
    }

    #[TestDox('Voto duplo que escapa da conferência para no UNIQUE: mesma mensagem e o contador não é tocado')]
    public function testVotoDuploPeloUnique(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.025000');
        $this->falhaAoGravar = new UniqueConstraintViolationException($this->createStub(ExcecaoDoDriver::class), null);

        try {
            $this->caso()->executar(RegistrarVotoInput::deUma(self::TOKEN, 10, (int) $this->opcao($pauta, 1)->getId()));
            self::fail('O segundo voto devia parar no UNIQUE.');
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame(RegistrarVotoUseCase::JA_VOTOU, $recusa->getMessage());
        }
        self::assertSame(['begin', 'insert', 'gravar', 'rollback'], $this->passos);
        self::assertSame([], $this->somados, 'o contador não é tocado');
    }

    #[TestDox('Pauta aguardando abertura: recusa sem gravar')]
    public function testPautaNaoAberta(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.025000', abrir: false);

        $this->assertRecusa(RegistrarVotoUseCase::PAUTA_NAO_ABERTA, (int) $this->opcao($pauta, 1)->getId());

        self::assertSame(['begin', 'rollback'], $this->passos);
    }

    #[TestDox('Pauta encerrada pela mesa: recusa sem gravar')]
    public function testPautaEncerrada(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.025000');
        $pauta->encerrar('Mesa', '', $this->relogio->now());

        $this->assertRecusa(RegistrarVotoUseCase::PAUTA_NAO_ABERTA, (int) $this->opcao($pauta, 1)->getId());
    }

    #[TestDox('Prazo vencido: grava o encerramento ("Prazo definido pela mesa"), confirma a transação e recusa o voto')]
    public function testPrazoVencido(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.025000', prazo: '+5 minutes');
        $this->relogio->modify('+6 minutes');

        $this->assertRecusa(RegistrarVotoUseCase::PRAZO_ENCERRADO, (int) $this->opcao($pauta, 1)->getId());

        self::assertSame(['begin', 'flush', 'commit'], $this->passos);
        self::assertSame(EstadoDaPauta::Encerrada, $pauta->getEstado());
        self::assertSame(PautaDaAssembleia::MOTIVO_DO_PRAZO, $pauta->getMotivoEncerramento());
    }

    #[TestDox('Unidade inapta: recusa antes de abrir a transação')]
    public function testUnidadeInapta(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.025000', situacao: 'inadimplente');

        $this->assertRecusa(RegistrarVotoUseCase::UNIDADE_INAPTA, (int) $this->opcao($pauta, 1)->getId());

        self::assertSame([], $this->passos);
    }

    #[TestDox('Opção de OUTRA pauta: recusa sem gravar')]
    public function testOpcaoDeOutraPauta(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.025000');
        $outra = new PautaDaAssembleia($pauta->getAssembleia(), 2, 'Orçamento');
        $alheia = new OpcaoDaPauta($outra, 1, 'Sim');
        $this->definirId($alheia, 99);

        $this->assertRecusa(RegistrarVotoUseCase::OPCAO_INVALIDA, 99);
    }

    #[TestDox('Pauta de outra assembleia (a busca travada não acha): recusa sem gravar')]
    public function testPautaDeOutraAssembleia(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.025000');

        $this->expectExceptionObject(new OperacaoInvalidaNaAssembleiaException(RegistrarVotoUseCase::PAUTA_NAO_ENCONTRADA));
        $this->caso()->executar(RegistrarVotoInput::deUma(self::TOKEN, 777, (int) $this->opcao($pauta, 1)->getId()));
    }

    #[TestDox('Token inexistente: CedulaNaoEncontrada (404), sem transação')]
    public function testTokenInexistente(): void
    {
        $this->unidades->method('findOneByTokenHash')->willReturn(null);

        $this->expectException(CedulaNaoEncontradaException::class);
        try {
            $this->caso()->executar(RegistrarVotoInput::deUma(self::TOKEN, 10, 1));
        } finally {
            self::assertSame([], $this->passos);
        }
    }

    #[TestDox('Escritório sem o Voto liberado: o link vale como inexistente (404)')]
    public function testEscritorioSemLiberacao(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.025000');
        $this->liberarOEscritorio(false);

        $this->expectException(CedulaNaoEncontradaException::class);
        $this->caso()->executar(RegistrarVotoInput::deUma(self::TOKEN, 10, (int) $this->opcao($pauta, 1)->getId()));
    }

    #[TestDox('A participação não tem nenhum campo que aponte para a opção (o voto só existe no contador)')]
    public function testParticipacaoNaoLigaAOpcao(): void
    {
        $tipos = [];
        foreach ((new \ReflectionClass(ParticipacaoNaPauta::class))->getProperties() as $propriedade) {
            $tipo = $propriedade->getType();
            $tipos[$propriedade->getName()] = $tipo instanceof \ReflectionNamedType ? $tipo->getName() : (string) $tipo;
        }

        self::assertNotContains(OpcaoDaPauta::class, $tipos);
        self::assertSame([], array_filter(array_keys($tipos), static fn (string $n): bool => str_contains(strtolower($n), 'opcao')));
    }

    #[TestDox('Pauta de 3 vagas: até 3 nomes numa só participação, o mesmo peso somado em cada um e nada gravado antes de validar')]
    public function testVariosNomes(): void
    {
        [, $pauta] = $this->cenarioComVagas(3);
        [$a, $b, $c] = [$this->opcao($pauta, 1), $this->opcao($pauta, 2), $this->opcao($pauta, 3)];

        $this->caso()->executar(new RegistrarVotoInput(self::TOKEN, 10, [(int) $a->getId(), (int) $c->getId()]));

        self::assertSame(['begin', 'insert', 'gravar', 'somar', 'flush', 'commit'], $this->passos);
        self::assertCount(1, $this->participacoesSalvas, 'uma participação só, não uma por nome');
        self::assertSame([[[$a, $c], '0.025000']], $this->somados);
        self::assertNotContains($b, $this->somados[0][0]);
    }

    #[TestDox('Mais nomes do que vagas: recusa sem gravar')]
    public function testMaisNomesQueVagas(): void
    {
        [, $pauta] = $this->cenarioComVagas(2);

        $this->assertRecusaDaLista(RegistrarVotoUseCase::NOMES_DEMAIS, [1, 2, 3], $pauta);
    }

    #[TestDox('Pauta de vaga única recusa dois nomes')]
    public function testVagaUnicaRecusaDois(): void
    {
        [, $pauta] = $this->cenario(PesoDoVoto::Fracao, '0.025000');

        $this->assertRecusaDaLista(RegistrarVotoUseCase::NOMES_DEMAIS, [1, 2], $pauta);
    }

    #[TestDox('Opção repetida na lista: recusa sem gravar')]
    public function testOpcaoRepetida(): void
    {
        [, $pauta] = $this->cenarioComVagas(3);

        $this->assertRecusaDaLista(RegistrarVotoUseCase::NOME_REPETIDO, [1, 1], $pauta);
    }

    #[TestDox('Abstenção junto com nome: recusa; a abstenção vale sozinha')]
    public function testAbstencaoEExclusiva(): void
    {
        [, $pauta] = $this->cenarioComVagas(3);

        $this->assertRecusaDaLista(RegistrarVotoUseCase::ABSTENCAO_EXCLUSIVA, [1, 0], $pauta);
    }

    #[TestDox('Abstenção sozinha numa pauta de várias vagas: registra')]
    public function testAbstencaoSozinhaComVariasVagas(): void
    {
        [, $pauta] = $this->cenarioComVagas(3);
        $abstencao = $pauta->getAbstencao();
        self::assertNotNull($abstencao);

        $this->caso()->executar(RegistrarVotoInput::deUma(self::TOKEN, 10, (int) $abstencao->getId()));

        self::assertSame([[[$abstencao], '0.025000']], $this->somados);
    }

    #[TestDox('Lista vazia: "Escolha ..." sem gravar')]
    public function testListaVazia(): void
    {
        $this->cenario(PesoDoVoto::Fracao, '0.025000');

        $this->expectExceptionObject(new OperacaoInvalidaNaAssembleiaException(RegistrarVotoUseCase::OPCAO_INVALIDA));
        try {
            $this->caso()->executar(new RegistrarVotoInput(self::TOKEN, 10, []));
        } finally {
            self::assertSame([], $this->somados);
            self::assertSame([], $this->participacoesSalvas);
        }
    }

    #[TestDox('Uma opção de outra pauta no meio da lista invalida tudo: nada é gravado')]
    public function testUmaAlheiaInvalidaTudo(): void
    {
        [, $pauta] = $this->cenarioComVagas(3);
        $outra = new PautaDaAssembleia($pauta->getAssembleia(), 2, 'Orçamento');
        $this->definirId(new OpcaoDaPauta($outra, 1, 'Sim'), 99);

        $this->expectExceptionObject(new OperacaoInvalidaNaAssembleiaException(RegistrarVotoUseCase::OPCAO_INVALIDA));
        try {
            $this->caso()->executar(new RegistrarVotoInput(self::TOKEN, 10, [(int) $this->opcao($pauta, 1)->getId(), 99]));
        } finally {
            self::assertSame([], $this->somados);
            self::assertSame([], $this->participacoesSalvas);
        }
    }

    /** @param list<int> $numeros números das opções (0 = abstenção) */
    private function assertRecusaDaLista(string $mensagem, array $numeros, PautaDaAssembleia $pauta): void
    {
        $ids = array_map(
            fn (int $n): int => (int) (0 === $n ? $pauta->getAbstencao() : $this->opcao($pauta, $n))?->getId(),
            $numeros,
        );
        try {
            $this->caso()->executar(new RegistrarVotoInput(self::TOKEN, 10, $ids));
            self::fail('O voto devia ser recusado: ' . $mensagem);
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame($mensagem, $recusa->getMessage());
        }
        self::assertSame([], $this->somados, 'recusa nunca soma no contador');
        self::assertSame([], $this->participacoesSalvas, 'recusa nunca grava participação');
    }

    /** Pauta 10 com N vagas, opções 1, 2, 3 e abstenção; abre a pauta. @return array{UnidadeDaAssembleia, PautaDaAssembleia} */
    private function cenarioComVagas(int $vagas): array
    {
        $cenario = $this->cenario(PesoDoVoto::Fracao, '0.025000');
        (new \ReflectionProperty($cenario[1], 'vagas'))->setValue($cenario[1], $vagas);
        $this->definirId(new OpcaoDaPauta($cenario[1], 3, 'Talvez'), 4);

        return $cenario;
    }

    #[TestDox('Número inexistente soma nulo sem perder a escolha válida ou multiplicar participação')]
    public function testNomeENuloNaMesmaParticipacao(): void
    {
        [, $pauta] = $this->cenarioComVagas(2);
        $nome = $pauta->getOpcoesDeVoto()[0];
        $this->pautas->expects(self::once())->method('somarNulos')->with($pauta, 1, '0.025000');
        $this->caso()->executar(new RegistrarVotoInput(self::TOKEN, 10, [(int) $nome->getId()], ['9']));
        self::assertCount(1, $this->participacoesSalvas);
        self::assertSame([[[$nome], '0.025000']], $this->somados);
    }

    #[TestDox('Nulo puro é uma participação e não escolhe candidatura nem abstenção')]
    public function testNuloPuro(): void
    {
        [, $pauta] = $this->cenarioComVagas(1);
        $this->pautas->expects(self::once())->method('somarNulos')->with($pauta, 1, '0.025000');
        $this->caso()->executar(new RegistrarVotoInput(self::TOKEN, 10, [], ['9']));
        self::assertCount(1, $this->participacoesSalvas);
        self::assertSame([[[], '0.025000']], $this->somados);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nulosInvalidos')]
    public function testNuloInvalidoNaoGrava(array $numeros): void
    {
        $this->cenarioComVagas(2);
        $this->pautas->expects(self::never())->method('somarNulos');
        try {
            $this->caso()->executar(new RegistrarVotoInput(self::TOKEN, 10, [], $numeros));
            self::fail('A lista inválida devia ser recusada.');
        } catch (OperacaoInvalidaNaAssembleiaException) {
            self::assertSame([], $this->participacoesSalvas);
            self::assertSame([], $this->somados);
        }
    }

    public function testNuloComAbstencaoNaoGrava(): void
    {
        [, $pauta] = $this->cenarioComVagas(2);
        $this->pautas->expects(self::never())->method('somarNulos');
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage(RegistrarVotoUseCase::ABSTENCAO_EXCLUSIVA);
        $this->caso()->executar(new RegistrarVotoInput(self::TOKEN, 10, [(int) $pauta->getAbstencao()->getId()], ['9']));
    }

    public function testFalhaNoContadorNuloDesfazTransacao(): void
    {
        $this->cenarioComVagas(1);
        $this->pautas->method('somarNulos')->willThrowException(new \RuntimeException('Falha do contador'));
        try {
            $this->caso()->executar(new RegistrarVotoInput(self::TOKEN, 10, [], ['9']));
            self::fail('A falha devia desfazer a transação.');
        } catch (\RuntimeException $erro) {
            self::assertSame('Falha do contador', $erro->getMessage());
            self::assertSame('rollback', $this->passos[array_key_last($this->passos)]);
            self::assertNotContains('commit', $this->passos);
        }
    }

    public static function nulosInvalidos(): iterable
    {
        yield 'repetido' => [['9', '9']];
        yield 'candidatura existente' => [['1']];
        yield 'dígitos insuficientes' => [['']];
        yield 'dígitos demais' => [['99']];
        yield 'letra' => [['x']];
        yield 'excesso de vagas' => [['7', '8', '9']];
        yield 'tipo incorreto' => [[9]];
        yield 'lista aninhada' => [[['9']]];
    }

    private function caso(): RegistrarVotoUseCase
    {
        $link = new UnidadeDoLink(new TokenDaUnidade(self::SEGREDO), $this->unidades, $this->liberacoes);

        return new RegistrarVotoUseCase($link, $this->pautas, $this->opcoes, $this->participacoes, $this->transacaoFalsa(), $this->relogio, new \App\Voto\Service\ComprovanteDoVoto('segredo-de-teste'));
    }

    /** Transação de mentira que anota begin/gravar/flush/commit/rollback em $this->passos. */
    private function transacaoFalsa(): TransacaoDoVoto
    {
        $anotar = function (string $passo): void {
            $this->passos[] = $passo;
        };

        return new class ($anotar, $this->falhaAoGravar) implements TransacaoDoVoto {
            public function __construct(
                private readonly \Closure $anotar,
                private readonly ?\Throwable $falhaAoGravar,
            ) {
            }

            public function executar(callable $trabalho): mixed
            {
                ($this->anotar)('begin');
                try {
                    $resultado = $trabalho();
                    ($this->anotar)('flush');
                    ($this->anotar)('commit');

                    return $resultado;
                } catch (\Throwable $erro) {
                    ($this->anotar)('rollback');
                    throw $erro;
                }
            }

            public function gravar(): void
            {
                ($this->anotar)('gravar');
                if (null !== $this->falhaAoGravar) {
                    throw $this->falhaAoGravar;
                }
            }
        };
    }

    private function assertRecusa(string $mensagem, int $opcaoId): void
    {
        try {
            $this->caso()->executar(RegistrarVotoInput::deUma(self::TOKEN, 10, $opcaoId));
            self::fail('O voto devia ser recusado: ' . $mensagem);
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame($mensagem, $recusa->getMessage());
        }
        self::assertSame([], $this->somados, 'recusa nunca soma no contador');
        self::assertSame([], $this->participacoesSalvas, 'recusa nunca grava participação');
    }

    /**
     * Assembleia publicada, pauta 10 com 1 Sim (id 1), 2 Não (id 2) e a abstenção (id 3), aberta pela mesa (com prazo
     * opcional), e a unidade do token.
     *
     * @return array{UnidadeDaAssembleia, PautaDaAssembleia}
     */
    private function cenario(PesoDoVoto $peso, string $fracao, bool $abrir = true, ?string $prazo = null, ?string $situacao = null): array
    {
        $assembleia = new Assembleia($this->tenant, 'AGO 2026', $peso, 'Gestor');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Aprovação das contas');
        $this->definirId($pauta, 10);
        foreach ([[1, 'Sim', false], [2, 'Não', false], [0, 'Abstenção', true]] as $i => [$numero, $rotulo, $abstencao]) {
            $this->definirId(new OpcaoDaPauta($pauta, $numero, $rotulo, $abstencao), $i + 1);
        }
        $tokens = new TokenDaUnidade(self::SEGREDO);
        new UnidadeDaAssembleia($assembleia, '102', str_repeat('b', 32), str_repeat('c', 64), 'A', fracao: '0.010000');
        $unidade = new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), $tokens->hash(self::TOKEN), 'A', $situacao, $fracao);
        $assembleia->publicar($this->relogio->now()->modify('-1 hour'));
        if ($abrir) {
            $agora = $this->relogio->now();
            $pauta->abrir('Mesa', 1, null === $prazo ? null : $agora->modify($prazo), $agora->modify('-10 minutes'));
        }
        $this->unidades->method('findOneByTokenHash')->willReturnCallback(
            static fn (string $hash): ?UnidadeDaAssembleia => $hash === $tokens->hash(self::TOKEN) ? $unidade : null,
        );
        $this->pautas->method('buscarTravadaDaAssembleia')->willReturnCallback(
            static fn (int $id, Assembleia $a): ?PautaDaAssembleia => 10 === $id && $a === $assembleia ? $pauta : null,
        );

        return [$unidade, $pauta];
    }

    private function opcao(PautaDaAssembleia $pauta, int $numero): OpcaoDaPauta
    {
        foreach ($pauta->getOpcoesDeVoto() as $opcao) {
            if ($opcao->getNumero() === $numero) {
                return $opcao;
            }
        }
        self::fail('Opção ' . $numero . ' não existe.');
    }

    private function liberarOEscritorio(bool $liberado): void
    {
        $liberacao = new LiberacaoDoVoto($this->tenant);
        $liberado ? $liberacao->liberar() : $liberacao->bloquear();
        $this->liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $this->liberacoes->method('findDoTenant')->willReturn($liberacao);
    }

    private function definirId(object $entidade, int $id): void
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);
    }
}
